<?php

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
        do {
            $moduleName = $this->prompt("Enter module name: ");
        } while ('' === $moduleName);
        
        $this->moduleName = $moduleName;

        echo "Please wait...\n";
        
        $serviceFactories = array();

        foreach ($this->getTables() as $table)
        {
            echo $table->getName(), "\n";
            $this->generateModel($table);
            $this->generateMapper($table);
            
            $tableName = $table->getName();
            $modelName = $this->toCamelCase($tableName);
            
            $serviceFactories[] = <<<CODE

                '$this->moduleName\\{$modelName}Mapper' =>  function(\$sm) {
                    \$tableGateway = \$sm->get('$this->moduleName\\{$modelName}TableGateway');
                    \$mapper = new \\$this->moduleName\Model\\$modelName\\{$modelName}Mapper(\$tableGateway);
                    return \$mapper;
                },
                '$this->moduleName\\{$modelName}TableGateway' => function(\$sm) {
                    \$dbAdapter = \$sm->get('Zend\Db\Adapter\Adapter');
                    \$resultSetPrototype = new \Zend\Db\ResultSet\ResultSet();
                    \$resultSetPrototype->setArrayObjectPrototype(new \\$this->moduleName\Model\\$modelName\\$modelName());
                    return new \Zend\Db\TableGateway\TableGateway('$tableName', \$dbAdapter, null, \$resultSetPrototype);
                },
CODE;
        }
        
        $factoriesCode = implode('', $serviceFactories);
        
        $moduleServiceCode = <<<MODULE
        return array(
            'factories' => array(
$factoriesCode
            ),
        );
MODULE;
        $this->writeFile("$this->workingDir/module/$this->moduleName/config/service.config.zk", $moduleServiceCode, false);
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
            
            foreach ($indexes as $index)
            {
                $camelCaseColumns = $index;
                
                array_walk($camelCaseColumns, array($this, 'toCamelCase'));
                
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
 
                $where = implode(",\n", $where);
                $indexCode[] = <<<CODE


    /**
     *
$varComments
     * @return $modelName
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
     * @return $modelName
     */
    public function get$modelName(\$id)
    {
        return \$this->tableGateway->select(array('$primaryKey[0]' => \$id))->current();
    }

    /**
     * @param $modelName \$model
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
     * @param $modelName|int \$model
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
use $this->moduleName\Model\\$modelName\\$modelName;

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
        $this->writeFile("$this->workingDir/module/$this->moduleName/src/$this->moduleName/Model/$modelName/{$modelName}Mapper.php", $code);
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
                throw new \Exception("\$key field does not exist in " . __CLASS__);
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
        
        $this->writeFile("$this->workingDir/module/$this->moduleName/src/$this->moduleName/Model/$modelName/$modelName.php", $code);
    }

    /**
     * 
     * @param string $filename
     * @param string $contents
     * @param boolean $generatePatchIfExists
     */
    protected function writeFile($filename, $contents, $generatePatchIfExists = true)
    {
        $dir = dirname($filename);
        
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        
        if (file_exists($filename)) {
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

