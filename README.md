Zodeken2
========

One-file Model/Mapper generator from database for Zend Framework 2.

The design pattern
========

Zodeken2 follows the Data Mapper pattern http://martinfowler.com/eaaCatalog/dataMapper.html. Models are true models, not table rows like Zodeken anymore. They're just the objects that contain values and setters, getters without knowing about database or relationships or anything else. We use Mappers to map those models to data sources, we mostly work with databases so Mapper's methods usually do SELECT/INSERT/UPDATE/DELETE operations.

Usage
========

1. Put zodeken2.php to root directory of your project.

```
/your-project/
----/config/
----/data/
----/module/
----/public/
----/vendor/
----/composer.json
----[...]
----/zodeken2.php <-- Put it here
```

2. Run `php zodeken2.php` in terminal. It will read your db configs and asks you for the module name. The 'php' command not found? Please install the php5-cli or php-cli for Linux or add the directory of your php.exe to your system path for Windows.

3. Enter your module name and wait.

4. Models, Mappers and ModelFactory will be created in `module/YourModule/src/YourModule/Model`.

5. Open your Module.php and alter the onBootstrap method.

```php
    public function onBootstrap(MvcEvent $e)
    {
        Model\ModelFactory::getInstance()->setServiceLocator($e->getApplication()->getServiceManager());
        [...]
    }
```
    
6. Use it in your code.

Get a Mapper object: `$mapper = \YourModule\Model\ModelFactory::getInstance()->getYourTableNameMapper()`
Construct a Model:
```php
$model = new \YourModule\Model\YourTableName\YourTableName;
$model->setSomeField($someField);
$model->setAnotherField($anotherField);
```
Save the model: `$mapper->saveYourTableName($model);`
Get a model set from database: `$mapper->getYourTableNameSetBySomeField($someField);`...

Important notes
========

1. Do not make any change to Model and ModelFactory files because they will always be overwritten.
2. Do not alter existing methods below the `__construct()` in Mapper classes.
3. Put your custom methods into Mapper classes between `protected $serviceLocator;` and `public function __construct(TableGateway $tableGateway)` like this:

```php
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    public function myCustomMethod($blahblah)
    {
        
    }

    public function myCustomMethodTwo($blahblah)
    {
        
    }

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }
```
    
If you change your tables, Zodeken2 will search for your custom methods and copy them to the new class automatically.

That's it.