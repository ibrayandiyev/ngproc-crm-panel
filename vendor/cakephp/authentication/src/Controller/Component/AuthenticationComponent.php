<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Authentication\Controller\Component;

use ArrayAccess;
use Authentication\AuthenticationServiceInterface;
use Authentication\Authenticator\PersistenceInterface;
use Authentication\Authenticator\StatelessInterface;
use Authentication\Authenticator\UnauthenticatedException;
use Cake\Controller\Component;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Exception;
use RuntimeException;

/**
 * Controller Component for interacting with Authentication.
 *
 */
class AuthenticationComponent extends Component implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * Configuration options
     *
     * - `logoutRedirect` - The route/URL to direct users to after logout()
     * - `requireIdentity` - By default AuthenticationComponent will require an
     *   identity to be present whenever it is active. You can set the option to
     *   false to disable that behavior. See allowUnauthenticated() as well.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'logoutRedirect' => false,
        'requireIdentity' => true,
        'identityAttribute' => 'identity',
    ];

    /**
     * List of actions that don't require authentication.
     *
     * @var string[]
     */
    protected $unauthenticatedActions = [];

    /**
     * Authentication service instance.
     *
     * @var \Authentication\AuthenticationServiceInterface|null
     */
    protected $_authentication;

    /**
     * Initialize component.
     *
     * @param array $config The config data.
     * @return void
     */
    public function initialize(array $config)
    {
        $controller = $this->getController();
        $this->setEventManager($controller->getEventManager());
    }

    /**
     * Triggers the Authentication.afterIdentify event for non stateless adapters that are not persistent either
     *
     * @return void
     */
    public function beforeFilter()
    {
        $authentication = $this->getAuthenticationService();
        $provider = $authentication->getAuthenticationProvider();

        if (
            $provider === null ||
            $provider instanceof PersistenceInterface ||
            $provider instanceof StatelessInterface
        ) {
            return;
        }

        $this->dispatchEvent('Authentication.afterIdentify', [
            'provider' => $provider,
            'identity' => $this->getIdentity(),
            'service' => $authentication,
        ], $this->getController());
    }

    /**
     * Returns authentication service.
     *
     * @return \Authentication\AuthenticationServiceInterface
     * @throws \Exception
     */
    public function getAuthenticationService()
    {
        if ($this->_authentication !== null) {
            return $this->_authentication;
        }

        $controller = $this->getController();
        $service = $controller->request->getAttribute('authentication');
        if ($service === null) {
            throw new Exception('The request object does not contain the required `authentication` attribute');
        }

        if (!($service instanceof AuthenticationServiceInterface)) {
            throw new Exception('Authentication service does not implement ' . AuthenticationServiceInterface::class);
        }

        $this->_authentication = $service;

        return $service;
    }

    /**
     * Start up event handler
     *
     * @return void
     * @throws Exception when request is missing or has an invalid AuthenticationService
     * @throws UnauthenticatedException when requireIdentity is true and request is missing an identity
     */
    public function startup()
    {
        if (!$this->getConfig('requireIdentity')) {
            return;
        }

        $request = $this->getController()->getRequest();
        $action = $request->getParam('action');
        if (in_array($action, $this->unauthenticatedActions, true)) {
            return;
        }

        $identity = $request->getAttribute($this->getConfig('identityAttribute'));
        if (!$identity) {
            throw new UnauthenticatedException('No identity found. You can skip this check by configuring  `requireIdentity` to be `false`.');
        }
    }

    /**
     * Set the list of actions that don't require an authentication identity to be present.
     *
     * Actions not in this list will require an identity to be present. Any
     * valid identity will pass this constraint.
     *
     * @param string[] $actions The action list.
     * @return $this
     */
    public function allowUnauthenticated(array $actions)
    {
        $this->unauthenticatedActions = $actions;

        return $this;
    }

    /**
     * Add to the list of actions that don't require an authentication identity to be present.
     *
     * @param string[] $actions The action or actions to append.
     * @return $this
     */
    public function addUnauthenticatedActions(array $actions)
    {
        $this->unauthenticatedActions = array_merge($this->unauthenticatedActions, $actions);
        $this->unauthenticatedActions = array_values(array_unique($this->unauthenticatedActions));

        return $this;
    }

    /**
     * Get the current list of actions that don't require authentication.
     *
     * @return string[]
     */
    public function getUnauthenticatedActions()
    {
        return $this->unauthenticatedActions;
    }

    /**
     * Gets the result of the last authenticate() call.
     *
     * @return \Authentication\Authenticator\ResultInterface|null Authentication result interface
     */
    public function getResult()
    {
        return $this->getAuthenticationService()->getResult();
    }

    /**
     * Returns the identity used in the authentication attempt.
     *
     * @return \Authentication\IdentityInterface|null
     */
    public function getIdentity()
    {
        $controller = $this->getController();
        $identity = $controller->request->getAttribute($this->getConfig('identityAttribute'));

        return $identity;
    }

    /**
     * Returns the identity used in the authentication attempt.
     *
     * @param string $path Path to return from the data.
     * @return mixed
     * @throws \RuntimeException If the identity has not been found.
     */
    public function getIdentityData($path)
    {
        $identity = $this->getIdentity();

        if ($identity === null) {
            throw new RuntimeException('The identity has not been found.');
        }

        return Hash::get($identity, $path);
    }

    /**
     * Replace the current identity
     *
     * Clear and replace identity data in all authenticators
     * that are loaded and support persistence. The identity
     * is cleared and then set to ensure that privilege escalation
     * and de-escalation include side effects like session rotation.
     *
     * @param \ArrayAccess $identity Identity data to persist.
     * @return $this
     */
    public function setIdentity(ArrayAccess $identity)
    {
        $controller = $this->getController();
        $service = $this->getAuthenticationService();

        $result = $service->clearIdentity(
            $controller->request,
            $controller->response
        );
        $result = $service->persistIdentity(
            $result['request'],
            $result['response'],
            $identity
        );

        $controller->setRequest($result['request']);
        $controller->response = $result['response'];

        return $this;
    }

    /**
     * Log a user out.
     *
     * Triggers the `Authentication.logout` event.
     *
     * @return string|null Returns null or `logoutRedirect`.
     */
    public function logout()
    {
        $controller = $this->getController();
        $result = $this->getAuthenticationService()->clearIdentity(
            $controller->request,
            $controller->response
        );

        $controller->request = $result['request'];
        $controller->response = $result['response'];

        $this->dispatchEvent('Authentication.logout', [], $controller);

        $logoutRedirect = $this->getConfig('logoutRedirect');
        if ($logoutRedirect === false) {
            return null;
        }

        return Router::normalize($logoutRedirect);
    }

    /**
     * Get the URL visited before an unauthenticated redirect.
     *
     * Reads from the current request's query string if available.
     *
     * Leverages the `unauthenticatedRedirect` and `queryParam` options in
     * the AuthenticationService.
     *
     * @return string|null
     */
    public function getLoginRedirect()
    {
        $controller = $this->getController();

        return $this->getAuthenticationService()->getLoginRedirect($controller->request);
    }
}
