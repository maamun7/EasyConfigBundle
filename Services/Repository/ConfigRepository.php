<?php

namespace Xiidea\EasyConfigBundle\Services\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Xiidea\EasyConfigBundle\Exception\UnrecognizedEntityException;
use Xiidea\EasyConfigBundle\Model\BaseConfig;

class ConfigRepository implements ConfigRepositoryInterface
{
    private EntityManagerInterface $em;
    private $entityClass;
    private $repository;

    public function __construct(EntityManagerInterface $em, $entityClass)
    {
        $this->em = $em;
        $this->isRecognizedEntity($entityClass);
        $this->entityClass = $entityClass;
        $this->repository = $em->getRepository($entityClass);
    }

    /**
     * @param $entityClass
     * @return void
     * @throws UnrecognizedEntityException
     */
    private function isRecognizedEntity($entityClass): void
    {
        $configurationClass = $entityClass;
        $entityObject = new $configurationClass('');

        if (!$entityObject instanceof BaseConfig) {
            throw new UnrecognizedEntityException();
        }
    }

    public function getConfigurationByUsernameAndGroup(string $username, string $groupKey)
    {
        $qb = $this->createQueryBuilder('c');

        return $qb
            ->setCacheable(true)
            ->setCacheRegion('config_group')
            ->where($qb->expr()->like('c.id', ':username'))
            ->setParameter('username', $username . '.' . $groupKey . '.%')
            ->orWhere(
                $qb->expr()->andX(
                    $qb->expr()->like('c.id', ':groupKey'),
                    $qb->expr()->eq('c.isGlobal', ':isGlobal')
                )
            )
            ->setParameter('groupKey', $groupKey . '.%')
            ->setParameter('isGlobal', 1)
            ->getQuery()
            ->getResult();
    }

    public function getConfigurationByUsernameAndKey(string $username, string $key)
    {
        $qb = $this->createQueryBuilder('c');

        return $qb
            ->setCacheable(true)
            ->setCacheRegion('config_key')
            ->where('c.id=:username')
            ->setParameter('username', $username . '.' . $key)
            ->orWhere('c.id=:key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getResult();
    }

    private function createQueryBuilder(string $alias)
    {
        return $this->repository->createQueryBuilder($alias);
    }

    public function loadAllByGroup($groupKey)
    {
        $qb = $this->createQueryBuilder('p');

        $configurations = $qb
            ->where($qb->expr()->like('p.id', ':group_key'))
            ->setCacheable(true)
            ->setCacheRegion('config_group')
            ->setParameter('group_key', $groupKey . '.%')
            ->getQuery()
            ->getResult();

        if (!$configurations) {
            return null;
        }

        $keyLength = strlen($groupKey) + 1;
        $return = [];

        /** @var BaseConfig $configuration */
        foreach ($configurations as $configuration) {
            $return[substr($configuration->getId(), $keyLength)] = $configuration;
        }

        return $return;
    }

    public function getValuesByGroupKey($configurationGroup)
    {
        return array_map([$this, 'getValue'], (array) $this->loadAllByGroup($configurationGroup));
    }

    protected function getValue($configuration)
    {
        return $configuration->getValue();
    }

    public function saveMultiple($baseKey, array $values = [], array $types = [])
    {
        foreach ($values as $key => $value) {
            $this->save($baseKey . ".{$key}", $value, isset($types[$key]) ?? $types[$key], false, false, false);
        }

        $this->em->flush();
    }

    /**
     * @param $key
     * @param $value
     * @param $type
     * @param bool $locked
     * @param bool $force
     * @param bool $flush
     * @return BaseConfig
     */
    public function save($key, $value, $type = null, bool $locked = false, bool $force = false, bool $flush = true)
    {
        $configuration = $this->repository->find($key);

        if (!$configuration) {
            $configuration = new $this->entityClass($key);
        } elseif ($configuration->isLocked() && !$force) {
            return $configuration;
        }

        $configuration->setValue($value);
        $configuration->setType($type);
        $configuration->setLocked((bool) $locked);

        $this->em->persist($configuration);

        if ($flush) {
            $this->em->flush($configuration);
        }

        return $configuration;
    }

    public function removeByKey($key)
    {
        $config = $this->repository->find($key);

        if ($config) {
            $this->em->remove($config);
            $this->em->flush();
        }
    }

    public function getConfigurationValue($key)
    {
        $configuration = $this->repository->find($key);

        if (null == $configuration) {
            return null;
        }

        return $configuration->getValue();
    }
}
