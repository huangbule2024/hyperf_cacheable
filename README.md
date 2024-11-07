# hyperf_cacheable
后期写成一个 `ConfigProvider` 代码复制到项目里面去，命名空间根据相应改下

# 依赖
`hyperf/cache`

### 场景
适合中小表场景，同时引入 `cacheGroupByField`  字段匹配某一个分组场景，比如同一个组、同一个公司，共一个分类等等

## 使用方法
在你的model里面 引入`CacheableTrait`
```
class UserModel extends Model
{
    use CacheableTrait;

    public $cacheGroupByField = "companyId"
}

```



