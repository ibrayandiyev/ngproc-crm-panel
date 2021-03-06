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
namespace Authentication\Identifier;

use Cake\Core\InstanceConfigTrait;

abstract class AbstractIdentifier implements IdentifierInterface
{
    use InstanceConfigTrait;

    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [];

    /**
     * Errors
     *
     * @var array
     */
    protected $_errors = [];

    /**
     * Constructor
     *
     * @param array $config Configuration
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * Returns errors
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->_errors;
    }
}
