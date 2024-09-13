<?php

declare(strict_types=1);

namespace App\Kernel\Cache;

use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Query\Builder;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class CacheQueryBuilder extends \Hyperf\Database\Query\Builder{

    #[Inject]
    private CacheInterface $cache;
    #[Inject]
    private LoggerInterface $logger;
    #[Inject]
    private ConfigInterface $config;

    /**
     * 重写runSelect
     * @return array|mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function runSelect()
    {
        if (!$this->config->get('cacheable.enabled')) {
            return parent::runSelect();
        }

        $ttl = $this->config->get('cacheable.ttl');
        $cacheKey = $this->getCacheKey();
        $modelCacheKey = $this->getModelCacheKey();
        $cacheVal = $this->cache->get($cacheKey, null);
        if (is_null($cacheVal)) {
            $cacheVal = parent::runSelect();
            $this->cache->set($cacheKey, $cacheVal, $ttl);
            $modelCacheVal = $this->cache->get($modelCacheKey, []);
            $modelCacheVal[] = $cacheKey;
            $this->cache->set($modelCacheKey, $modelCacheVal, $ttl);
        }
        return $cacheVal;
    }

    public function flushCache()
    {
        if (!$this->config->get('cacheable.enabled')) {
            return;
        }
        $modelCacheKey = $this->getModelCacheKey();
        $this->logger->debug("flush-cache-for: " . $modelCacheKey);
        $modelCacheVal = $this->cache->get($modelCacheKey, []);
        $this->cache->deleteMultiple($modelCacheVal);
        $this->cache->delete($modelCacheKey);
    }

    /**
     * Build a cache key based on the SQL statement and its bindings
     *
     * @return string
     */
    protected function getCacheKey(): string
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        if (! empty($bindings)) {
            $bindings = Arr::join($this->getBindings(), '_');

            $sql = $sql . '_' . $bindings;
        }

        return $this->config->get('cacheable.prefix') . ":" . substr(md5($sql), 8, -8);
    }

    /**
     * @param string|null $modelClass
     * @return string
     */
    protected function getModelCacheKey(): string
    {
        return $this->config->get('cacheable.prefix') . ":" . $this->from;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor);
    }


    /**
     * @param array $values
     * @return int
     */
    public function update(array $values)
    {
        $this->flushCache();

        return parent::update($values);
    }

    /**
     * @param array $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        $this->flushCache();

        return parent::updateFrom($values);
    }

    /**
     * @param array $values
     * @return bool
     */
    public function insert(array $values)
    {
        $this->flushCache();

        return parent::insert($values);
    }

    /**
     * @param array $values
     * @param       $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->flushCache();

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @param array $values
     * @return int
     */
    public function insertOrIgnore(array $values): int
    {
        $this->flushCache();

        return parent::insertOrIgnore($values);
    }

    /**
     * @param array $columns
     * @param       $query
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        $this->flushCache();

        return parent::insertUsing($columns, $query);
    }

    /**
     * @param array $values
     * @param       $uniqueBy
     * @param       $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->flushCache();

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param $id
     * @return int
     */
    public function delete($id = null)
    {
        $this->flushCache();

        return parent::delete($id);
    }

    /**
     * @return void
     */
    public function truncate()
    {
        $this->flushCache();

        parent::truncate();
    }
}

