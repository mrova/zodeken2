<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_USER_NOTICE);

$_SERVER['REQUEST_URI'] = '/';

require 'init_autoloader.php';

$application = Zend\Mvc\Application::init(require 'config/application.config.php');

$zodeken2 = new Zodeken2($application, __DIR__);
$zodeken2->run();

class Zodeken2
{

    /**
     *
     * @var Zend\Db\Adapter\Adapter
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
     * @param Zend\Mvc\Application $application
     */
    public function __construct(Zend\Mvc\Application $application, $workingDir)
    {
        $this->workingDir = $workingDir;

        $this->dbAdapter = $application->getServiceManager()->get('Zend\Db\Adapter\Adapter');

        if (!$this->dbAdapter) {
            throw new Exception("Database is not configured");
        }
    }

    /**
     *
     * @return Zend\Db\Metadata\Object\TableObject[]
     */
    protected function getTables()
    {
        $metadata = new \Zend\Db\Metadata\Metadata($this->dbAdapter);

        return $metadata->getTables();
    }

    public function run()
    {
        echo "\n\nWARNING: please backup your existing code!!!\n\n";
        do
        {
            $moduleName = $this->prompt("Enter module name: ");
        } while ('' === $moduleName);

        $this->moduleName = $moduleName;

        echo "Please wait...\n";

        $serviceFactoryMethods = array();

        foreach ($this->getTables() as $table)
        {
            echo $table->getName(), "\n";
            $this->generateModel($table);
            $this->generateMapper($table);

            $tableName = $table->getName();
            $modelName = $this->toCamelCase($tableName);

            $getMapperMethod = 'get' . $modelName . 'Mapper';
            $getTableGatewayMethod = 'get' . $modelName . 'TableGateway';

            $serviceFactoryMethods[] = <<<CODE

    /**
     * @return \Zend\Db\TableGateway\TableGateway
     */
    private function $getTableGatewayMethod()
    {
        \$dbAdapter = \$this->serviceLocator->get('Zend\Db\Adapter\Adapter');
        \$resultSetPrototype = new \Zend\Db\ResultSet\ResultSet();
        \$resultSetPrototype->setArrayObjectPrototype(new \\$this->moduleName\Model\\$modelName\\$modelName());
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

        $factoriesCode = implode('', $serviceFactoryMethods);

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
        $this->writeFile("$this->workingDir/module/$this->moduleName/src/$this->moduleName/Model/ModelFactory.php", $factoryCode, false, true);
    }

    protected function generateMapper(Zend\Db\Metadata\Object\TableObject $table)
    {
        $modelName = $this->toCamelCase($table->getName());

        $primaryKey = array();
        $indexes = array();

        foreach ($table->getConstraints() as $constraint)
        {
            /* @var $constraint Zend\Db\Metadata\Object\ConstraintObject */

            $constraintType = $constraint->getType();

            if ('PRIMARY KEY' === $constraintType) {
                $primaryKey = $constraint->getColumns();
            }

            $indexes[] = $constraint->getColumns();
        }

        if (isset($indexes[0])) {
            $indexCode = array();

            $functionNames = array();

            foreach ($indexes as $index)
            {
                $camelCaseColumns = $index;

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

                $functionNameResultSet = "get{$modelName}SetBy" . implode('And', $camelCaseColumns);
                $functionNameResult = "get{$modelName}By" . implode('And', $camelCaseColumns);
                
                if (isset($functionNames[$functionNameResult]) || isset($functionNames[$functionNameResultSet])) {
                    continue;
                }
                
                $functionNames[$functionNameResult] = 1;
                $functionNames[$functionNameResultSet] = 1;
                
                $argList = array();
                $varComments = array();

                foreach ($vars as $var)
                {
                    $argList[] = '$' . $var;
                    $varComments[] = "     * @param mixed $$var";
                }
                $argList = implode(', ', $argList);
                $varComments = implode("\n", $varComments);

                $where = array();

                foreach ($index as $offset => $indexColumn)
                {
                    $where[] = "'$indexColumn' => $$vars[$offset]";
                }

                $where = implode(",\n            ", $where);
                $indexCode[] = <<<CODE


    /**
     *
$varComments
     * @return \\$this->moduleName\Model\\$modelName\\$modelName
     */
    public function $functionNameResult($argList)
    {
        return \$this->tableGateway->select(array($where))->current();
    }


    /**
     *
$varComments
     * @return \Zend\Db\ResultSet\ResultSet
     */
    public function $functionNameResultSet($argList)
    {
        return \$this->tableGateway->select(array($where));
    }
CODE;
            }

            $indexCode = implode('', $indexCode);
        } else {
            $indexCode = '';
        }

        if (count($primaryKey) == 1) {
            $primaryKeyCamelCase = $this->toCamelCase($primaryKey[0]);
            $mappingCode = <<<CODE

    /**
     * @param int \$id
     * @return \\$this->moduleName\Model\\$modelName\\$modelName
     */
    public function get$modelName(\$id)
    {
        return \$this->tableGateway->select(array('$primaryKey[0]' => \$id))->current();
    }

    /**
     * @param \\$this->moduleName\Model\\$modelName\\$modelName \$model
     */
    public function save$modelName($modelName \$model)
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
     * @param \\$this->moduleName\Model\\$modelName\\$modelName|int \$model
     */
    public function delete$modelName(\$model)
    {
        if (\$model instanceof $modelName) {
            \$id = \$model->get$primaryKeyCamelCase();
        } else {
            \$id = \$model;
        }

        \$this->tableGateway->delete(array('$primaryKey[0]' => \$id));
    }
CODE;
        } else {
            $mappingCode = '';
        }

        $code = <<<TABLE
<?php

namespace $this->moduleName\Model\\$modelName;

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
        $filename = "$this->workingDir/module/$this->moduleName/src/$this->moduleName/Model/$modelName/{$modelName}Mapper.php";

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

    protected function generateModel(Zend\Db\Metadata\Object\TableObject $table)
    {
        $modelName = $this->toCamelCase($table->getName());

        $fieldsCode = array();
        $getterSetters = array();

        foreach ($table->getColumns() as $column)
        {
            /* @var $column \Zend\Db\Metadata\Object\ColumnObject */
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

class $modelName
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

        $this->writeFile("$this->workingDir/module/$this->moduleName/src/$this->moduleName/Model/$modelName/$modelName.php", $code, false, true);
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

