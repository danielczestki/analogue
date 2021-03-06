<?php
namespace Analogue\ORM\System;

use Exception;
use Analogue\ORM\EntityMap;
use Analogue\ORM\Repository;
use Analogue\ORM\System\Mapper;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Analogue\ORM\Exceptions\MappingException;
use Analogue\ORM\Plugins\AnaloguePluginInterface;

class Manager {

	/**
	 * Database Manager
	 * 
	 * @var DatabaseManager|Analogue
	 */
	protected static $db;

	/**
	 * Key value store of entity classes and corresponding maps.
	 * 
	 * @var array
	 */
	protected static $entityClasses = [];

	/**
	 * Key value store of Value Classes and corresponding maps
	 * 
	 * @var array
	 */
	protected static $valueClasses = [];

	/**
	 * Loaded Mappers
	 * 
	 * @var array
	 */
	protected static $mappers = [];

	/**
	 * Loaded Repositories
	 *
	 * @var array
	 */
	protected static $repositories = [];

	/**
	 * Event dispatcher instance
	 * 
	 * @var \Illuminate\Contracts\Events\Dispatcher
	 */
	protected static $eventDispatcher;

	/**
	 * Available Analogue Events
	 * 
	 * @var array
	 */
	protected static $events = ['initializing', 'initialized', 'store', 'stored',
		'creating', 'created', 'updating', 'updated', 'deleting', 'deleted' ];

	/**
	 * @param DatabaseManager|Analogue $connectionProvider       
	 * @param Dispatcher $event 
	 */
	public function __construct($connectionProvider, Dispatcher $event)
	{
		static::$db = $connectionProvider;

		static::$eventDispatcher = $event;
	}

	/**
	 * Create a mapper for a given entity
	 * 
	 * @param \Analogue\ORM\Mappable|string $entity
	 * @param mixed $entityMap 
	 * @return Mapper
	 */
	public static function mapper($entity, $entityMap = null)
	{
		if(! is_string($entity)) $entity = get_class($entity);

		// Return existing mapper instance if exists.
		if(array_key_exists($entity, static::$mappers))
		{
			return static::$mappers[$entity];
		}

		if(! is_null($entityMap)) static::register($entity, $entityMap);

		$entityMap = static::getEntityMapInstanceFor($entity);

		// Check if the entity map is set on a different connection
		// than the default one.
		if ( ($connection = $entityMap->getConnection() ) != null) 
		{
			static::$mappers[$entity] = new Mapper($entityMap, static::$db->connection($connection), static::$eventDispatcher);
		}
		else
		{
			static::$mappers[$entity] = new Mapper($entityMap, static::$db->connection(), static::$eventDispatcher);
		}

		return static::$mappers[$entity];
	}

	/**
	 * Get the Repository instance for the given Entity 
	 * 
	 * @param  \Analogue\ORM\Mappable|string $entity 
	 * @return \Analogue\ORM\Repository
	 */
	public static function repository($entity)
	{
		if(! is_string($entity)) $entity = get_class($entity);

		// First we check if the repository is not already created.
		if(array_key_exists($entity, static::$repositories))
		{
			return static::$repositories[$entity];
		}

		static::$repositories[$entity] = new Repository(static::mapper($entity));
		
		return static::$repositories[$entity];
	}

	/**
	 * Get the entity map instance for a custom entity
	 * 
	 * @param  string|object $entity 
	 * @return Mappable
	 */
	protected static function getEntityMapInstanceFor($entity)
	{
		if(! is_string($entity))
		{
			$entity = get_class($entity);
		}

		// If the entity class doesn't exist in the entity array
		// we register it.
		if(! array_key_exists($entity, static::$entityClasses))
		{
			static::register($entity);
		}
		
		$map = static::$entityClasses[$entity];

		if(is_null($map))
		{
			// Check if an EntityMap exist in the same namespace
			// as the entity.
			if (class_exists($entity.'Map'))
			{
				$map = $entity.'Map';
			}
			else 
			{
				// Generate an EntityMap obeject
				$map = static::generateBlankMap();
			}
		}

		if(is_string($map))
		{
			$map = new $map;
		}
		
		$map->setClass($entity);
		
		static::$entityClasses[$entity] = $map;

		return $map;

	}	

	/**
	 * Dynamically create an entity map for a custom entity class
	 * 
	 * @return EntityMap         
	 */
	protected static function generateBlankMap()
	{
		return new EntityMap;
	}

	/**
	 * Register an entity 
	 * 
	 * @param  string|Mappable $entity    entity's class name
	 * @param  string $entityMap map's class name
	 * @return void
	 */
	public static function register($entity, $entityMap = null)
	{
		if(! is_string($entity) ) $entity = get_class($entity);

		if (static::isRegisteredEntity($entity))
		{
			throw new MappingException("Entity $entity is already registered.");
		}

		static::$entityClasses[$entity] = $entityMap;
	}

	/**
	 * Register a Value Object
	 * 
	 * @param  string|ValueObject $valueObject 
	 * @param  string $valueMap    
	 * @return void
	 */
	public static function registerValueObject($valueObject, $valueMap = null)
	{
		if(! is_string($valueObject) ) $valueObject = get_class($valueObject);

		if(is_null($valueMap))
		{
			$valueMap = $valueObject.'Map';
		}

		if(! class_exists($valueMap))
		{
			throw new MappingException("$valueMap doesn't exists");
		}

		static::$valueClasses[$valueObject] = $valueMap;
	}

	/**
	 * Get the Value Map for a given Value Object Class
	 * 
	 * @param  string $valueObject 
	 * @return \Analogue\ORM\ValueMap
	 */
	public static function getValueMap($valueObject)
	{
		if(! array_key_exists($valueObject, static::$valueClasses))
		{
			static::registerValueObject($valueObject);
		}
		$valueMap = new static::$valueClasses[$valueObject];

		$valueMap->setClass($valueObject);

		return $valueMap;
	}

	/**
	 * Instanciate a new Value Object instance
	 * 
	 * @param  string $valueObject 
	 * @return ValueObject
	 */
	public static function getValueObjectInstance($valueObject)
	{
		$prototype = unserialize(sprintf('O:%d:"%s":0:{}',
			strlen($valueObject),
            			$valueObject
         			)
        		);
		return $prototype;
	}

	/**
	 * Register Analogue Plugin
	 * 
	 * @param  AnaloguePluginInterface $plugin 
	 * @return void
	 */
	public static function registerPlugin(AnaloguePluginInterface $plugin)
	{
		$plugin->register();
	}

	/**
	 * Check if the entity is already registered
	 * 
	 * @param  string|object  $entity
	 * @return boolean         
	 */
	public static function isRegisteredEntity($entity)
	{
		if (! is_string($entity)) $entity = get_class($entity);

		return in_array($entity, static::$entityClasses) ? true: false;
	}

	/**
	 * Register event listeners that will be fired regardless the type
	 * of the entity.
	 * 
	 * @param  string $event  
	 * @param  closure|string $callback 
	 * @return void
	 */
	public static function registerGlobalEvent($event, $callback)
	{
		if (! in_array($event, static::$events)) 
		{
			throw new \Exception("Analogue : Event $event doesn't exist");
		}
		static::$eventDispatcher->listen("analogue.{$event}.*", $callback);
	}

	/**
	 * Shortcut to Mapper store
	 * 
	 * @param  mixed $entity
	 * @return mixed
	 */
	public static function store($entity)
	{
		return static::mapper($entity)->store($entity);
	}

	/**
	 * Shortcut to Mapper delete
	 * 
	 * @param  mixed $entity
	 * @return mixed
	 */
	public static function delete($entity)
	{
		return static::mapper($entity)->delete($entity);
	}

	/**
	 * Shortcut to Mapper query
	 * 
	 * @param  mixed $entity
	 * @return \Analogue\System\Query
	 */
	public static function query($entity)
	{
		return static::mapper($entity)->query();
	}

	/**
	 * Shortcut to Mapper Global Query
	 * 
	 * @param  mixed $entity
	 * @return \Analogue\System\Query
	 */
	public static function globalQuery($entity)
	{
		return static::mapper($entity)->globalQuery();
	}
	
}