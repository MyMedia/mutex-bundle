<?php

namespace IXarlie\MutexBundle\Model;

/**
 * Class LockerManager
 *
 * @author Carlos Dominguez <ixarlie@gmail.com>
 */
class LockerManager implements LockerManagerInterface
{
    /**
     * @var \NinjaMutex\Lock\LockInterface
     */
    private $locker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \NinjaMutex\Mutex[]
     */
    private $locks;

    /**
     * @param \NinjaMutex\Lock\LockInterface $locker
     */
    public function __construct(\NinjaMutex\Lock\LockInterface $locker, \Psr\Log\LoggerInterface $logger = null)
    {
        $this->locker = $locker;
        $this->logger = $logger;
        $this->locks  = [];
    }

    /**
     * @param string $name
     * @return \NinjaMutex\Mutex
     */
    private function getOrCreateLock($name)
    {
        if (!$this->hasLock($name)) {
            $locker = $this->locker;
            switch (true) {
                case $locker instanceof \IXarlie\MutexBundle\Lock\LockTTLInterface:
                    $mutex = new \IXarlie\MutexBundle\Lock\MutexTTL($name, $locker);
                    break;
                default:
                    $mutex = new \NinjaMutex\Mutex($name, $locker);
                    break;
            }
            $this->locks[$name] = $mutex;
        }
        return $this->locks[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock($name, $timeout = null)
    {
        $mutex = $this->getOrCreateLock($name);
        return $mutex->acquireLock($timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock($name)
    {
        $mutex = $this->getOrCreateLock($name);
        return $mutex->releaseLock();
    }

    /**
     * {@inheritdoc}
     */
    public function isAcquired($name)
    {
        $mutex = $this->getOrCreateLock($name);
        return $mutex->isAcquired();
    }

    /**
     * {@inheritdoc}
     */
    public function isLocked($name)
    {
        $mutex = $this->getOrCreateLock($name);
        return $mutex->isLocked();
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasLock($name)
    {
        return isset($this->locks[$name]) ? true : false;
    }

    /**
     * @param string $name
     * @param int    $ttl
     * @param null $timeout
     */
    public function acquireLockTTL($name, $ttl, $timeout = null)
    {
        $mutex = $this->getOrCreateLock($name);
        // if mutex does not have ttl capabilities, acquire without ttl
        if (!$mutex instanceof \IXarlie\MutexBundle\Lock\MutexTTL) {
            return $mutex->acquireLock($timeout);
        }
        return $mutex->acquireLockTTL($ttl, $timeout);
    }
}
