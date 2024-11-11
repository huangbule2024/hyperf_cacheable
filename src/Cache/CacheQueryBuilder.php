<?php

declare(strict_types=1);

namespace App\Kernel\Cache;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\Grammar;
use Hyperf\Database\Query\Processors\Processor;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * CacheQueryBuilder
 * @author hbl
 * @date 2024/09/13
 */
class CacheQueryBuilder extends \Hyperf\Database\Query\Builder{

    #[Inject]
    private Redis $redis;
    #[Inject]
    private LoggerInterface $logger;
    #[Inject]
    private ConfigInterface $config;

    private $model;

    /**
     * 缓存组key，比如saas系统里面的companyId
     * @var mixed|null
     */
    private $cacheGroupByField;

    /**
     * 缓存组key所对应的值，比如companyId 对应的值 = 1
     * @var null|integer
     */
    private $cacheGroupByFieldValue = null;

    /**
     * 是否卡其缓存
     * @var mixed
     */
    private $cacheable = false;

    /**
     * Create a new query builder instance.
     */
    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        \Hyperf\DbConnection\Model\Model $model = null
    ) {
        parent::__construct($connection, $grammar, $processor);
        $this->model = $model;
        $this->cacheGroupByField = $this->model->cacheGroupByField;
        $this->cacheable = $this->config->get('cacheable.enabled');
    }

    /**
     * 重写runSelect
     * @return array|mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function runSelect()
    {
        //1. 判断是否开启缓存
        if (!$this->cacheable) {
            return parent::runSelect();
        }
        //2. 排除like语句
        $arrOperator = array_column($this->wheres, 'operator');
        if (in_array('like', $arrOperator)) {
            return parent::runSelect();
        }
        //3. 是否根据某一个字段分组缓存，否则就是全局缓存
        if ($this->cacheGroupByField) {
            $this->cacheGroupByFieldValue = null;
            //只支持简单的单个查询
            foreach ($this->wheres as $where) {
                if (isset($where['column']) && $where['column'] == $this->cacheGroupByField && $where['operator'] == '=') {
                    $this->cacheGroupByFieldValue = $where['value'];
                    break;
                }
            }
            if (!$this->cacheGroupByFieldValue) {
                return parent::runSelect();
            }
        }
        $ttl = $this->config->get('cacheable.ttl');
        $cacheKey = $this->getCacheKey();
        $modelCacheKey = $this->getModelCacheKey();
        $cacheVal = $this->redis->get($cacheKey);
        if ($cacheVal === false) {
            $cacheVal = serialize(parent::runSelect());
            $this->redis->set($cacheKey, $cacheVal, ['EX' => $ttl]);
            //全局cacheKey
            $this->redis->sAdd($modelCacheKey, $cacheKey);
            //某分组下cacheKey
            if ($this->cacheGroupByField) {
                $this->redis->sAdd($this->getGroupByFieldCollectCacheKey(), $cacheKey);
            }
        }
        return unserialize($cacheVal);
    }

    /**
     * 删除缓存
     * @param $cacheGroupByFieldValue string  外部可以手动指定删除
     * @return void
     * @throws \RedisException
     */
    public function flushCache($cacheGroupByFieldValue = null)
    {
        if ($cacheGroupByFieldValue) {
            $this->cacheGroupByFieldValue = $cacheGroupByFieldValue;
        }
        if (!$this->cacheable) {
            return;
        }
        if ($this->cacheGroupByFieldValue) {
            //删除某个分组下所有缓存
            $cacheKey = $this->getGroupByFieldCollectCacheKey();
            $arr = $this->redis->sMembers($cacheKey);
            if (!empty($arr))
                $this->redis->del($arr);

            $this->redis->del($cacheKey);
            //同时从全局中删除
            $modelCacheKey = $this->getModelCacheKey();
            $this->redis->sRem($modelCacheKey, ...$arr);
        } else {
            //从全局中取，然后删除
            $cacheKey = $this->getModelCacheKey();
            $arr = $this->redis->sMembers($cacheKey);
            if (!empty($arr))
                $this->redis->del($arr);

            $this->redis->del($cacheKey);
        }
    }

    /**
     * sql缓存key
     * @return string
     */
    protected function getCacheKey(): string
    {
        $sql = $this->toRawSql();
        return $this->getModelCacheKey() . ":" . substr(md5($sql), 8, -8);
    }

    /**
     * 一个表的全局key
     * @param string|null $modelClass
     * @return string
     */
    protected function getModelCacheKey(): string
    {
        return $this->config->get('cacheable.prefix') . ":" . $this->from;
    }

    /**
     * 根据表里面某一个字段生成的缓存集合
     * @return string
     */
    protected function getGroupByFieldCollectCacheKey()
    {
        $cacheKey = $this->getModelCacheKey();
        if ($this->cacheGroupByField && $this->cacheGroupByFieldValue) {
            $cacheKey .= ":" . $this->cacheGroupByField . $this->cacheGroupByFieldValue;
        }
        return $cacheKey;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor, $this->model);
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param null|string $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->logger->debug("Insert Insert Id");
        if ($this->cacheGroupByField) {
            $this->cacheGroupByFieldValue = $values[$this->cacheGroupByField] ?? null;
            if (!$this->cacheGroupByFieldValue) {
                $errMsg = 'insertGetId 失败，插入数据不存在` ' . $this->cacheGroupByField . "`" ;
                $this->logger->error($errMsg  . var_export($values, true));
                throw new \Exception($errMsg );
            }
        }
        $this->flushCache();
        return parent::insertGetId($values, $sequence);
    }

    /**
     * @param array $values
     * @return int
     */
    public function update(array $values)
    {
        $this->logger->debug('update');
        if ($this->cacheGroupByField) {
            $this->cacheGroupByFieldValue = $this->model->{$this->cacheGroupByField};
            if (!$this->cacheGroupByFieldValue) {
                foreach ($this->wheres as $where) {
                    if (isset($where['column']) && $where['column'] == $this->cacheGroupByField && $where['operator'] == '=') {
                        $this->cacheGroupByFieldValue = $where['value'];
                        break;
                    }
                }
            }
            if (!$this->cacheGroupByFieldValue) {
                $errMsg = 'update 失败，请检查插入是否存在` ' . $this->cacheGroupByField . "`" . var_export($this->wheres, true) ;
                throw new \Exception($errMsg);
            }
        }
        $this->flushCache();
        return parent::update($values);
    }

    /**
     * @param array $values
     * @return bool
     */
    public function insert(array $values)
    {
        $this->logger->debug('insert');
        if ($this->cacheGroupByField) {
            $arrFlushed = [];
            if (count($values) != count($values, 1)) {
                //二维数组
                foreach ($values as $value) {
                    $this->cacheGroupByFieldValue = $value[$this->cacheGroupByField] ?? null;
                    if (!$this->cacheGroupByFieldValue)
                        throw new \Exception('insert 失败，请检查插入是否存在` ' . $this->cacheGroupByField . "`");

                    //防止多次删缓存
                    if (empty($arrFlushed[$this->cacheGroupByField])) {
                        $this->flushCache();
                        $arrFlushed[$this->cacheGroupByField] = 1;
                    }
                }
            } else {
                $this->cacheGroupByFieldValue = $value[$this->cacheGroupByField] ?? null;
                if (!$this->cacheGroupByFieldValue)
                    throw new \Exception('insert 失败，请检查插入是否存在` ' . $this->cacheGroupByField . "`");

                $this->flushCache();
            }
        } else {
            $this->flushCache();
        }
        return parent::insert($values);
    }

    /**
     * Delete a record from the database.
     * @param null|mixed $id
     * @return int
     */
    public function delete($id = null)
    {
        $this->logger->debug('delete');
        if ($this->cacheGroupByField) {
            $this->cacheGroupByFieldValue = $this->model->{$this->cacheGroupByField};
            if (!$this->cacheGroupByFieldValue) {
                foreach ($this->wheres as $where) {
                    if (isset($where['column']) && $where['column'] == $this->cacheGroupByField && $where['operator'] == '=') {
                        $this->cacheGroupByFieldValue = $where['value'];
                        break;
                    }
                }
            }
            if (!$this->cacheGroupByFieldValue) {
                throw new \Exception('delete 失败，where条件不存在` ' . $this->cacheGroupByField . "`");
            }
        }
        $this->flushCache();
        return parent::delete($id);
    }


    /**
     * 暂时删除全部，因为基本用不到
     * @param array $values
     * @return int
     */
    public function insertOrIgnore(array $values): int
    {
        $this->flushCache();
        return parent::insertOrIgnore($values);
    }

    /**
     * 暂时删除全部，因为基本用不到
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
     * 暂时删除全部，因为基本用不到
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
     * @return void
     */
    public function truncate()
    {
        $this->flushCache();
        parent::truncate();
    }


    /**
     * 可以对外使用，手动开启关闭
     * @param $enabled
     * @return void
     */
    public function cache($enabled = true)
    {
        $this->cacheable = $enabled;
    }
}

