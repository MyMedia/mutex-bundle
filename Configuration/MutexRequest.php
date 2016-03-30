<?php

namespace IXarlie\MutexBundle\Configuration;

/**
 * Class MutexLock
 *
 * @author Carlos Dominguez <ixarlie@gmail.com>
 *
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
class MutexRequest
{
    const MODE_BLOCK = 'block';
    const MODE_CHECK = 'check';

    /**
     * Lock name
     * @var string
     */
    protected $name;

    /**
     * Block mode will acquire the resource in case is not already locked.
     * Check mode just check if the resource is released in order to be executed.
     * @var string
     */
    protected $mode = self::MODE_BLOCK;

    /**
     * Custom message for ConflictHttpException
     * @var string
     */
    protected $message;

    /**
     * Some lockers implements a time-to-live option.
     * This option is ignored for non compatible lockers.
     * @var int
     */
    protected $ttl;

    /**
     * Registered service to create the lock. Reduced or complete name can be used.
     * (redis == i_xarlie_mutex.locker_redis)
     * @var string
     */
    protected $service;

    public function __construct(array $values)
    {
        foreach ($values as $k => $v) {
            if (!method_exists($this, $name = 'set'.$k)) {
                throw new \RuntimeException(sprintf('Unknown key "%s" for annotation "@%s".', $k, get_class($this)));
            }
            $this->$name($v);
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * return MutexRequest
     */
    public function setName($name)
    {
        if (empty($name)) {
            throw new \LogicException('@MutexRequest name is mandatory field');
        }
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @param string $mode
     *
     * return MutexRequest
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string $message
     *
     * return MutexRequest
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }

    /**
     * @return int
     */
    public function getTtl()
    {
        return $this->ttl;
    }

    /**
     * @param int $ttl
     *
     * return MutexRequest
     */
    public function setTtl($ttl)
    {
        $this->ttl = $ttl;
    }

    /**
     * @return string
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * @param string $service
     *
     * return MutexRequest
     */
    public function setService($service)
    {
        if (empty($service)) {
            throw new \LogicException('@MutexRequest service is mandatory field');
        }
        if (!preg_match('/^i_xarlie_mutex.locker_/', $service)) {
            $service = 'i_xarlie_mutex.locker_' . $service;
        }
        $this->service = $service;
    }
}
