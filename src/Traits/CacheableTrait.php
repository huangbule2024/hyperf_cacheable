<?php

declare(strict_types=1);

namespace App\Traits;

use App\Kernel\Cache\CacheQueryBuilder;

/**
 * 缓存，适合数据表数据不是太大，大表慎用
 * @author hbl
 * @date 2024/09/13
 */
trait CacheableTrait
{

    /**
     * 重写newBaseQueryBuilder转到CacheQueryBuilder做一层缓存处理
     * @return CacheQueryBuilder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();
        return new CacheQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
            $this
        );
    }

}
