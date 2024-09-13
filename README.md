# hyperf_cacheable
后期写成一个 `ConfigProvider` 代码复制到项目里面去，命名空间根据相应改下

### 场景
适合小表，不这么改变的场景

## 使用方法
在你的model里面 引入`CacheableTrait`
```
class UserModel extends Model
{
    use CacheableTrait;
}

```

