<?php

use Zend\Config\Reader\Ini;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\Metadata;
use Zend\Db\Metadata\Object\ColumnObject;
use Zend\Db\Metadata\Object\ConstraintObject;
use Zend\Db\Metadata\Object\TableObject;
use Zend\Mvc\Application;

error_reporting(E_ALL & ~E_NOTICE & ~E_USER_NOTICE);

$_SERVER['REQUEST_URI'] = '/';

require 'init_autoloader.php';

$application = Application::init(require 'config/application.config.php');

$zodeken2 = new Zodeken2($application, __DIR__);
$zodeken2->run();

class Zodeken2
{
    /**
     * @var string
     */
    const DEFAULT_DB_ADAPTER_KEY = 'Zend\Db\Adapter\Adapter';

    /**
     *
     * @var array
     */
    protected $configs = array();

    /**
     *
     * @var Adapter
     */
    protected $dbAdapter;

    /**
     *
     * @var string
     */
    protected $moduleName;

    /**
     *
     * @var string
     */
    protected $workingDir;

    /**
     *
     * @param Application $application
     */
    public function __construct(Application $application, $workingDir)
    {
        $this->workingDir = $workingDir;

        do {
            $dbAdapterServiceKey = $this->prompt(
                'Service key for db adapter [Zend\Db\Adapter\Adapter]: '
            );

            if ('' === $dbAdapterServiceKey) {
                $dbAdapterServiceKey = self::DEFAULT_DB_ADAPTER_KEY;
            }

            if (!$application->getServiceManager()->has($dbAdapterServiceKey)) {
                $isAdapterOk = false;
                echo "Service key $dbAdapterServiceKey does exist", PHP_EOL;
            } else {
                $isAdapterOk = true;
            }

        } while (!$isAdapterOk);

        $this->dbAdapter = $application->getServiceManager()->get(
            $dbAdapterServiceKey
        );

        if (!$this->dbAdapter) {
            throw new Exception("Database is not configured");
        }
    }

    public function run()
    {
        $configFile = $this->workingDir . '/zodeken2.ini';
        $configs = array();

        // read ini configs
        if (is_readable($configFile)) {
            $iniReader = new Ini();
            $configs = $iniReader->fromFile($configFile);
        } else {
            echo "\nNotice: $configFile does not exist\n";
        }

        echo "\n\nWARNING: please backup your existing code!!!\n\n";

        do {
            $moduleName = $this->prompt("Enter module name: ");
        } while ('' === $moduleName);

        $this->moduleName = $moduleName;

        $tableList = isset($configs['tables'])
            && isset($configs['tables'][$moduleName])
            && '' !== $configs['tables'][$moduleName]
            ? preg_split('#\s*,\s*#', $configs['tables'][$moduleName])
            : null;

        if (null === $tableList) {
            $shouldContinue = $this->prompt(
                "Table list of $moduleName module is not set, "
                    + "continue with ALL tables in db? y/n [y]: "
            );

            if ('n' === strtolower($shouldContinue)) {
                echo 'Exiting...', PHP_EOL;
            }
        }

        echo "Please wait...\n";

        $serviceFactoryMethods = array();

        foreach ($this->getTables() as $table) {

            $tableName = $table->getName();

            // check if the table is in the list, can use in_array because
            // performance does not really matter here
            if (null !== $tableList && !in_array($tableName, $tableList)) {
                continue;
            }

            echo $tableName, "\n";

            $this->generateModel($table);
            $this->generateMapper($table);

            $serviceFactoryMethods[] = $this->getMapperFactoryCode($table);
        }

        $factoryCode = $this->getModelFactoryCode(
            implode('', $serviceFactoryMethods)
        );

        $this->writeFile(
            sprintf(
                '%s/module/%s/src/%s/Model/ModelFactory.php',
                $this->workingDir,
                $this->moduleName,
                $this->moduleName
            ),
            $factoryCode,
            false,
            true
        );
    }

    /**
     * Get code of model factory class for each module
     *
     * @param string $factoriesCode
     * @return string
     */
    protected function getModelFactoryCode($factoriesCode)
    {
        return

        $factoryCode = <<<MODULE
<?php

namespace $this->moduleName\Model;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class ModelFactory implements ServiceLocatorAwareInterface
{

    /**
     * @var ServiceLocatorInterface
     */
    protected \$serviceLocator;

    /**
     * @var \\$this->moduleName\Model\ModelFactory
     */
    static protected \$instance;

    /**
     * @return \\$this->moduleName\Model\ModelFactory
     */
    static public function getInstance()
    {
        if (!self::\$instance) {
            self::\$instance = new self;
        }
        return self::\$instance;
    }

    private function __construct() {}

    private function __clone() {}
$factoriesCode

    public function setServiceLocator(ServiceLocatorInterface \$serviceLocator)
    {
        \$this->serviceLocator = \$serviceLocator;
    }

    public function getServiceLocator()
    {
        return \$this->serviceLocator;
    }
}
MODULE;
    }

    /**
     *
     * @param TableObject $table
     * @return string
     */
    protected function getMapperFactoryCode(TableObject $table)
    {
        $tableName = $table->getName();

        $modelName = $this->toCamelCase($tableName);

        $getMapperMethod = 'get' . $modelName . 'Mapper';
        $getTableGatewayMethod = 'get' . $modelName . 'TableGateway';

        return <<<CODE

    /**
     * @return \Zend\Db\TableGateway\TableGateway
     */
    private function $getTableGatewayMethod()
    {
        \$dbAdapter = \$this->serviceLocator->get('Zend\Db\Adapter\Adapter');
        \$resultSetPrototype = new \Zend\Db\ResultSet\ResultSet();
        \$resultSetPrototype->setArrayObjectPrototype(new \\$this->moduleName\Model\\$modelName\\{$modelName}Model());
        return new \Zend\Db\TableGateway\TableGateway('$tableName', \$dbAdapter, null, \$resultSetPrototype);
    }

    /**
     * @return \\$this->moduleName\Model\\$modelName\\{$modelName}Mapper
     */
    public function $getMapperMethod()
    {
        \$tableGateway = \$this->$getTableGatewayMethod();
        \$mapper = new \\$this->moduleName\Model\\$modelName\\{$modelName}Mapper(\$tableGateway);
        \$mapper->setServiceLocator(\$this->serviceLocator);
        return \$mapper;
    }
CODE;
    }

    /**
     *
     * @param TableObject $table
     */
    protected function generateMapper(TableObject $table)
    {
        $modelName = $this->toCamelCase($table->getName());

        $primaryKey = array();
        $indexes = array();
        $mappingCode = '';
        $indexCode = '';

        foreach ($table->getConstraints() as $constraint)
        {
            /* @var $constraint ConstraintObject */

            $constraintType = $constraint->getType();

            if ('PRIMARY KEY' === $constraintType) {
                $primaryKey = $constraint->getColumns();
            }

            $indexes[] = $constraint->getColumns();
        }

        if (isset($indexes[0])) {
            $indexCodeArray = array();

            foreach ($indexes as $index)
            {
                $singleIndexCode = $this->getMethodsOfIndex($index, $modelName);

                if (!is_string($singleIndexCode)) {
                    continue;
                }

                $indexCodeArray[] = $singleIndexCode;
            }

            $indexCode = implode('', $indexCodeArray);
        }

        if (count($primaryKey) === 1) {
            $mappingCode = $this->getPrimaryKeyCode($primaryKey, $modelName);
        }

        $code = <<<TABLE
<?php

namespace $this->moduleName\Model\\$modelName;

use Zend\Db\ResultSet\ResultSet;
use Zend\Db\TableGateway\TableGateway;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class {$modelName}Mapper implements ServiceLocatorAwareInterface
{
    /**
     * @var TableGateway
     */
    protected \$tableGateway;

    /**
     * @var ServiceLocatorInterface
     */
    protected \$serviceLocator;

    public function __construct(TableGateway \$tableGateway)
    {
        \$this->tableGateway = \$tableGateway;
    }
$mappingCode
$indexCode

    public function setServiceLocator(ServiceLocatorInterface \$serviceLocator)
    {
        \$this->serviceLocator = \$serviceLocator;
    }

    public function getServiceLocator()
    {
        return \$this->serviceLocator;
    }
}
TABLE;
        $filename = sprintf(
            '%s/module/%s/src/%s/Model/%s/%sMapper.php',
            $this->workingDir,
            $this->moduleName,
            $this->moduleName,
            $modelName,
            $modelName
        );

        if (file_exists($filename)) {
            $existingCode = file_get_contents($filename);
            $startPoint = 'protected $serviceLocator;';
            $endPoint = 'public function __construct(TableGateway $tableGateway)';
            $customCode = substr($existingCode, strpos($existingCode, $startPoint) + strlen($startPoint), strpos($existingCode, $endPoint) - strpos($existingCode, $startPoint) - strlen($startPoint));

            $code = preg_replace('#' . preg_quote($startPoint, '#') . '\s*' . preg_quote($endPoint, '#') . '#si', "$startPoint$customCode$endPoint", $code);

            $startPoint = "namespace $this->moduleName\Model\\$modelName;";
            $endPoint = "class {$modelName}Mapper implements ServiceLocatorAwareInterface";

            $customCode = substr($existingCode, strpos($existingCode, $startPoint) + strlen($startPoint), strpos($existingCode, $endPoint) - strpos($existingCode, $startPoint) - strlen($startPoint));

            $code = preg_replace('#' . preg_quote($startPoint, '#') . '.*' . preg_quote($endPoint, '#') . '#si', "$startPoint$customCode$endPoint", $code);
        }

        $this->writeFile($filename, $code, false, true);
    }

    /**
     *
     * @param array $primaryKey
     * @param string $modelName
     * @return string
     */
    protected function getPrimaryKeyCode($primaryKey, $modelName)
    {
        $primaryKeyCamelCase = $this->toCamelCase($primaryKey[0]);

        return <<<CODE

    /**
     * @param int \$id
     * @return {$modelName}Model
     */
    public function get{$modelName}Model(\$id)
    {
        return \$this->tableGateway->select(array('$primaryKey[0]' => \$id))->current();
    }

    /**
     * @param {$modelName}Model \$model
     */
    public function save{$modelName}Model({$modelName}Model \$model)
    {
        \$id = \$model->get$primaryKeyCamelCase();

        if (!\$id) {
            \$this->tableGateway->insert(\$model->toArray());
        } else {
            \$this->tableGateway->update(\$model->toArray(), array('$primaryKey[0]' => \$id));
        }
    }

    /**
     *
     * @param {$modelName}Model|int \$model
     */
    public function delete{$modelName}Model(\$model)
    {
        if (\$model instanceof {$modelName}Model) {
            \$id = \$model->get$primaryKeyCamelCase();
        } else {
            \$id = \$model;
        }

        \$this->tableGateway->delete(array('$primaryKey[0]' => \$id));
    }
CODE;
    }

    /**
     *
     * @param type $index
     * @param string $modelName
     * @return string|boolean
     */
    protected function getMethodsOfIndex($index, $modelName)
    {
        $camelCaseColumns = $index;
        $functionNames = array();

        foreach ($camelCaseColumns as &$camelCaseColumn)
        {
            $camelCaseColumn = $this->toCamelCase($camelCaseColumn);
        }

        $vars = array();

        foreach ($camelCaseColumns as $var)
        {
            $var[0] = strtolower($var[0]);
            $vars[] = $var;
        }

        $functionNameResultSet = "get{$modelName}ModelSetBy" . implode('And', $camelCaseColumns);
        $functionNameResult = "get{$modelName}ModelBy" . implode('And', $camelCaseColumns);

        if (isset($functionNames[$functionNameResult]) || isset($functionNames[$functionNameResultSet])) {
            return false;
        }

        $functionNames[$functionNameResult] = 1;
        $functionNames[$functionNameResultSet] = 1;

        $argListArray = array();
        $varCommentsArray = array();

        foreach ($vars as $var)
        {
            $argListArray[] = '$' . $var;
            $varCommentsArray[] = "     * @param mixed $$var";
        }
        $argList = implode(', ', $argListArray);
        $varComments = implode("\n", $varCommentsArray);

        $whereArray = array();

        foreach ($index as $offset => $indexColumn)
        {
            $whereArray[] = "'$indexColumn' => $$vars[$offset]";
        }

        $where = implode(",\n            ", $whereArray);

        return <<<CODE


    /**
     *
$varComments
     * @return {$modelName}Model
     */
    public function $functionNameResult($argList)
    {
        return \$this->tableGateway->select(array($where))->current();
    }


    /**
     *
$varComments
     * @return ResultSet
     */
    public function $functionNameResultSet($argList)
    {
        return \$this->tableGateway->select(array($where));
    }
CODE;
    }

    protected function generateModel(TableObject $table)
    {
        $modelName = $this->toCamelCase($table->getName());

        $fieldsCode = array();
        $getterSetters = array();

        foreach ($table->getColumns() as $column)
        {
            /* @var $column ColumnObject */
            $fieldName = $column->getName();
            $fieldNameCamelCase = $varName = $this->toCamelCase($fieldName);
            $varName[0] = strtolower($varName[0]);
            $defaultValue = var_export($column->getColumnDefault(), true);

            $getterSetters[] = "
    public function get$fieldNameCamelCase()
    {
        return \$this->data['$fieldName'];
    }

    public function set$fieldNameCamelCase(\$$varName)
    {
        \$this->data['$fieldName'] = \$$varName;
    }";

            $fieldsCode[] = "
        '$fieldName' => $defaultValue,";
        }

        $fieldsCode = "array(" . implode('', $fieldsCode) . '
    )';
        $getterSettersCode = implode('', $getterSetters);

        $code = <<<MODEL
<?php

namespace $this->moduleName\Model\\$modelName;

class {$modelName}Model
{

    protected \$data = $fieldsCode;
$getterSettersCode

    public function exchangeArray(\$data)
    {
        foreach (\$data as \$key => \$value)
        {
            if (!array_key_exists(\$key, \$this->data)) {
                continue;//throw new \Exception("\$key field does not exist in " . __CLASS__);
            }
            \$this->data[\$key] = \$value;
        }
    }

    public function toArray()
    {
        return \$this->data;
    }
}
MODEL;

        $this->writeFile(
            sprintf(
                '%s/module/%s/src/%s/Model/%s/%sModel.php',
                $this->workingDir,
                $this->moduleName,
                $this->moduleName,
                $modelName,
                $modelName
            ),
            $code,
            false,
            true
        );
    }

    /**
     *
     * @return TableObject[]
     */
    protected function getTables()
    {
        $metadata = new Metadata($this->dbAdapter);

        return $metadata->getTables();
    }

    /**
     *
     * @param string $filename
     * @param string $contents
     * @param boolean $generatePatchIfExists
     * @param boolean $overwrite
     */
    protected function writeFile($filename, $contents, $generatePatchIfExists = true, $overwrite = false)
    {
        $dir = dirname($filename);

        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        if ($overwrite) {
            file_put_contents($filename, $contents);
        } elseif ("service.config.zk" !== substr($filename, -17) && file_exists($filename)) {
            $zodekenFile = $filename . '.zk';
            file_put_contents($zodekenFile, $contents);

            if ($generatePatchIfExists && 'Linux' === PHP_OS) {
                `diff -u $filename $zodekenFile > $filename.patch`;
            }
        } else {
            file_put_contents($filename, $contents);
        }
    }

    /**
     *
     * @param string $name
     * @return string
     */
    protected function toCamelCase($name)
    {
        return implode('', array_map('ucfirst', explode('_', $name)));
    }

    /**
     *
     * @param string $message
     * @return string
     */
    protected function prompt($message)
    {
        echo $message;
        return trim(fgets(STDIN));
    }

}

