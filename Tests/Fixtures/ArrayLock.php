<?php

namespace IXarlie\MutexBundle\Tests\Fixtures;

use NinjaMutex\Lock\LockAbstract;

/**
 * Class ArrayLock
 *
 * @author Carlos Dominguez <ixarlie@gmail.com>
 */
class ArrayLock extends LockAbstract
{
    /**
     * @param  string $name
     * @param  bool $blocking
     * @return bool
     */
    protected function getLock($name, $blocking)
    {
        $content = serialize($this->getLockInformation());
        if (isset($this->locks[$name])) {
            return false;
        }

        $this->locks[$name] = $content;
        
        return true;
    }

    /**
     * @param  string $name
     * @return bool
     */
    public function releaseLock($name)
    {
        unset($this->locks[$name]);
        return true;
    }

    /**
     * @param  string $name
     * @return bool
     */
    public function isLocked($name)
    {
        return isset($this->locks[$name]);
    }
}
