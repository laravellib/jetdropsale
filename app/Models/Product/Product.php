<?php

namespace App\Models\Product;

use App\Enums\ProductStatusType;
use App\Models\GoodsContract;
use App\SuperClass\Facades\Hashids;
use App\SuperClass\Model;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableInterface;
use ScoutElastic\Searchable;
use Symfony\Component\Routing\Exception\InvalidParameterException;

/**
 * App\Models\Product\Product
 *
 * @property int $id
 * @property string $title
 * @property int|null $category_id
 * @property int $status Сейчас не используется, неизвестно когда будет и для чего.
 * @property float $price
 * @property int $amount
 * @property string $description
 * @property string $image
 * @property mixed $images
 * @property int|null $ali_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $categoriesPath
 * @property-read \Illuminate\Database\Eloquent\Collection|\OwenIt\Auditing\Models\Audit[] $audits
 * @property-read \App\Models\Product\Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product\ProductVariant[] $combinations
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product\ProductOption[] $options
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product\ProductDetail[] $details
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Product\MyProduct[] $myProducts
 * @method static \Illuminate\Database\Eloquent\Builder|\App\SuperClass\Model lastOrFail($column = 'created_at')
 * @mixin \Eloquent
 * @property int $is_available
 * @property \Highlight|null $highlight
 * @property-read mixed $ship_countries
 * @method static \Illuminate\Database\Eloquent\Builder|\App\SuperClass\Model filter(\App\Filters\AbstractFilter $filters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\SuperClass\Model last($column = 'created_at')
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Product\Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Product\Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Product\Product query()
 */
class Product extends Model implements AuditableInterface, GoodsContract
{
    use AuditableTrait;
    use Searchable;
    use ProductGoodsTrait;

    protected $appends = ['shipCountries'];

    protected static function boot()
    {
        parent::boot(); // TODO: Change the autogenerated stub

        self::updating(function (self $model) {
            if ($model->isDirty(['status'])) {
                $model->status == ProductStatusType::UNAVAILABLE && $model->is_available = ProductStatusType::UNAVAILABLE;
                $model->status == ProductStatusType::AVAILABLE && $model->is_available = ProductStatusType::AVAILABLE;
            }
        });

        self::updated(function (self $model) {
            if ($model->isDirty(['status'])) {
                try {
                    $model->status == ProductStatusType::UNAVAILABLE && $model->unsearchable();
                    $model->status == ProductStatusType::AVAILABLE && $model->searchable();
                } catch (\Exception $e) {
                }
            }
            cache()->forget(self::getViewCacheKey($model->id));
        });

        self::deleted(function (self $model) {
            cache()->forget(self::getViewCacheKey($model->id));
        });
    }

    /**
     * @return mixed
     */
    public function getShipCountriesAttribute()
    {
        return ProductOption::where([
            ['product_id', $this->id],
            ['ali_option_id', ProductOption::SHIPPING_FROM_OPTION]
        ])->get()->pluck('value')->toArray();
    }

    /** @var string $indexConfigurator */
    protected $indexConfigurator = ProductIndexConfigurator::class;

    protected $searchRules = [
        //
    ];

    protected $mapping = [
        'properties' => [
            'description'       => ['type' => 'text', 'analyzer' => 'my_analyzer'],
            'price'             => ['type' => 'float'],
        ]
    ];

    protected $fillable = [
        'title',
        'status',
        'price',
        'amount',
        'description',
        'ali_id',
        'image',
        'images',
        'categoriesPath',
        'shipCountries',
    ];

    public function toSearchableArray()
    {
        return array_merge($this->toArray(), [
            'ship_countries' => $this->getShipCountriesAttribute()
        ]);
    }

    /**
     * @param array $combinations
     */
    public function skuGenerate(array $combinations): string
    {
        $data = array_merge([$this->id], array_values($combinations));

        return Hashids::encode($data);
    }

    /**
     * @return HasMany|ProductOption[]
     */
    public function options()
    {
        return $this->hasMany(ProductOption::class);
    }

    /**
     * @return HasMany|ProductVariant[]
     */
    public function combinations()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param $urls
     * @return array|mixed|null
     */
    public function imageFromUrl($urls)
    {
        $isString = false;
        if (is_string($urls)) {
            $urls = [$urls];
            $isString = true;
        }

        if (! $urls) {
            return $isString ? '' : [];
        }

        if (! is_array($urls)) {
            throw new InvalidParameterException('Urls is not array ' . print_r($urls, 1));
        }
        $newUrls = [];
        foreach ($urls as $url) {
            $allowed = ['png', 'gif', 'jpg', 'jpeg'];
            $urlInfo = pathinfo($url);
            if (isset($urlInfo['extension']) && ! in_array($urlInfo['extension'], $allowed)) {
                Log::warning('Неизвестное расширение у ссылки на картинку', ['url' => $url]);

                return null;
            }

            $path = public_path('storage/images/products/' . $this->idToDirs());
            if (! is_dir($path)) {
                mkdir($path, 0775, true);
            }
            $image = rtrim($path, '/') . '/' . hash('crc32', $url) . '.' . $urlInfo['extension'];
            if (! file_exists($image)) {
                try {
                    (new Client())->get($url, ['sink' => $image]);
                } catch (\Exception $e) {
                    Log::error('Cannot get image', ['error'=>$e]);
                    continue;
                }
            }
            $newUrls[] = str_replace(public_path(), '', $image) ;
        }
        return $isString ? isset($newUrls[0]) ? $newUrls[0] : '' : $newUrls;
    }

    public function idToDirs()
    {
        $hash = md5($this->id);

        return substr($hash, 0, 2) . "/" . substr($hash, 2, 2) . "/" . $this->id;
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProductDetail::class);
    }

    public function myProducts(): HasMany
    {
        return $this->hasMany(MyProduct::class);
    }

    public static function getViewCacheKey($id)
    {
        return 'view.product.' . $id;
    }

    public function getAliLink()
    {
        return 'https://alitems.com/g/1e8d1144949591a4efe116525dc3e8/?i=5&ulp=https://www.aliexpress.com/item//' .
            $this->ali_id .
            '.html&aff_short_key=6iEY3FE';
    }

    public function transformAudit(array $data): array
    {
        return parent::transformAudit($data);
    }
}
