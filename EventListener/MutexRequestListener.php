<?php

namespace IXarlie\MutexBundle\EventListener;

use IXarlie\MutexBundle\Configuration\MutexRequest;
use IXarlie\MutexBundle\Manager\LockerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Class MutexRequestListener
 *
 * @author Carlos Dominguez <ixarlie@gmail.com>
 */
class MutexRequestListener implements EventSubscriberInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->reader    = $container->get('annotation_reader');
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::CONTROLLER => 'onKernelController',
            KernelEvents::TERMINATE  => 'onKernelTerminate'
        );
    }

    /**
     * @param FilterControllerEvent $event
     *
     * @throws HttpException
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        if (!is_array($controller = $event->getController())) {
            return;
        }

        $controller = $event->getController();
        $className  = class_exists('Doctrine\Common\Util\ClassUtils') ?
            ClassUtils::getClass($controller[0]) :
            get_class($controller[0])
        ;
        $methodName = $controller[1];

        $configurations = $this->loadConfiguration($className, $methodName);
        if (empty($configurations)) {
            return;
        }

        foreach ($configurations as $configuration) {
            $this->applyDefaults($configuration, $className, $methodName);

            $service = $this->getMutexService($configuration);
            if (null === $service) {
                throw new \LogicException(sprintf(
                    'To use the @MutexRequest tag, you need to register a valid locker provider. %s is not registered',
                    $configuration->getService()
                ));
            }

            switch ($configuration->getMode()) {
                case MutexRequest::MODE_BLOCK:
                    $this->block($service, $configuration);
                    break;
                case MutexRequest::MODE_CHECK:
                    $this->check($service, $configuration);
                    break;
                case MutexRequest::MODE_QUEUE:
                    $this->queue($service, $configuration);
                    break;
                case MutexRequest::MODE_FORCE:
                    $this->force($service, $configuration);
                    break;
                default:
                    break;
            }
        }
    }

    public function onKernelTerminate(PostResponseEvent $event)
    {
        // @TODO release locks here? __destroy methods works actually
    }

    /**
     * @param string $className
     * @param string $methodName
     * @return MutexRequest[]
     */
    private function loadConfiguration($className, $methodName)
    {
        $object = new \ReflectionClass($className);
        $method = $object->getMethod($methodName);

        $classConfigurations  = $this->getConfigurations(
            $this->reader->getClassAnnotations($object),
            $className
        );
        $methodConfigurations = $this->getConfigurations(
            $this->reader->getMethodAnnotations($method),
            $className,
            $methodName
        );

        $configurations = array_merge($classConfigurations, $methodConfigurations);

        return $configurations;
    }

    /**
     * @param array $annotations
     * @return MutexRequest[]
     */
    private function getConfigurations(array $annotations, $className, $methodName = null)
    {
        $configurations = [];
        foreach ($annotations as $configuration) {
            if ($configuration instanceof MutexRequest) {

                $mode = $configuration->getMode();
                if (null === $mode || '' === $mode) {
                    $message = "@MutexRequest mode option is required in $className";
                    if ($methodName) {
                        $message = $message . "::$methodName";
                    }
                    throw new \LogicException($message);
                } elseif (!defined('\IXarlie\MutexBundle\Configuration\MutexRequest::MODE_' . strtoupper($mode))) {
                    $message = "@MutexRequest \"$mode\" is not a valid mode option in $className";
                    if ($methodName) {
                        $message = $message . "::$methodName";
                    }
                    throw new \LogicException($message);
                }

                $configurations[] = $configuration;
            }
        }
        return $configurations;
    }

    /**
     * @param MutexRequest $configuration
     * @return LockerManagerInterface|null
     */
    private function getMutexService(MutexRequest $configuration)
    {
        try {
            return $this->container->get($configuration->getService());
        } catch (ServiceNotFoundException $e) {
        }
        return;
    }

    /**
     * Returns a unique hash for user
     *
     * @return string
     */
    private function getIsolatedName()
    {
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $this->container->get('security.token_storage');
        if ($token = $tokenStorage->getToken()) {
            return md5($token->serialize());
        }
        return;
    }

    /**
     * @param MutexRequest $configuration
     * @param string       $className
     * @param string       $methodName
     */
    private function applyDefaults(MutexRequest $configuration, $className, $methodName)
    {
        $name = $configuration->getName();
        if (null === $name || '' === $name) {
            $configuration->setName(sprintf('%s_%s', $className, $methodName));
        }

        if ($configuration->isUserIsolation()) {
            $name = sprintf('%s_%s', $configuration->getName(), $this->getIsolatedName());
            $configuration->setName($name);
        }

        $service = $configuration->getService();
        if (null === $service || '' === $service) {
            $configuration->setService('i_xarlie_mutex.locker');
        } elseif (!preg_match('i_xarlie_mutex.locker_', $service)) {
            $configuration->setService('i_xarlie_mutex.locker_' . $service);
        }

        $message = $configuration->getMessage();
        if (null === $message || '' === $message) {
            $configuration->setMessage($this->container->getParameter('i_xarlie_mutex.http_exception.message'));
        }
        $httpCode = $configuration->getHttpCode();
        if (null === $httpCode || '' === $httpCode) {
            $configuration->setHttpCode($this->container->getParameter('i_xarlie_mutex.http_exception.code'));
        }
    }

    /**
     * Attempt to acquire the lock.
     *
     * @param LockerManagerInterface $service
     * @param MutexRequest           $configuration
     *
     * @throws HttpException
     */
    private function block(LockerManagerInterface $service, MutexRequest $configuration)
    {
        $this->check($service, $configuration);
        $service->acquireLock($configuration->getName(), null, $configuration->getTtl());
    }

    /**
     * Check if the lock is locked or not.
     *
     * @param LockerManagerInterface $service
     * @param MutexRequest           $configuration
     *
     * @throws HttpException
     */
    private function check(LockerManagerInterface $service, MutexRequest $configuration)
    {
        if (!$service->isLocked($configuration->getName())) {
            return;
        }
        throw new HttpException($configuration->getHttpCode(), $configuration->getMessage());
    }

    /**
     * @param LockerManagerInterface $service
     * @param MutexRequest $configuration
     */
    private function queue(LockerManagerInterface $service, MutexRequest $configuration)
    {
        $service->acquireLock($configuration->getName(), null, $configuration->getTtl());
    }

    /**
     * @param LockerManagerInterface $service
     * @param MutexRequest $configuration
     */
    private function force(LockerManagerInterface $service, MutexRequest $configuration)
    {
        if ($service->isLocked($configuration->getName())) {
            $service->releaseLock($configuration->getName());
        }
        $service->acquireLock($configuration->getName(), null, $configuration->getTtl());
    }
}
