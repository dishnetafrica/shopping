<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','name','icon','group','active','sort'];
    protected $casts = ['active'=>'boolean','sort'=>'integer'];

    /** The standard starter set for a cross-border trading business (Family Shoppers). */
    public static function defaults(): array
    {
        return [
            // group, name, icon
            ['operating','Rent','🏠'],
            ['operating','Electricity / Power','💡'],
            ['operating','Water','💧'],
            ['operating','Internet / Airtime','📶'],
            ['operating','Office Supplies','🖇️'],
            ['operating','Cleaning','🧹'],
            ['staff','Salaries','👥'],
            ['staff','Staff Advance','💵'],
            ['staff','Commission','🎯'],
            ['staff','Food / Allowance','🍽️'],
            ['logistics','Transport / Fuel','⛽'],
            ['logistics','Bus Freight','🚌'],
            ['logistics','Boda / Delivery','🛵'],
            ['logistics','Vehicle Repair','🔧'],
            ['financial','Bank Charges','🏦'],
            ['financial','Mobile Money Fees','📱'],
            ['financial','Exchange Loss','💱'],
            ['financial','Government Fees / Tax','🏛️'],
            ['financial','Licence / Renewal','📋'],
            ['other','Marketing / Ads','📣'],
            ['other','Repairs / Maintenance','🛠️'],
            ['other','Miscellaneous','📦'],
        ];
    }

    public static function seedFor(int $tenantId): int
    {
        $n = 0; $sort = 0;
        foreach (self::defaults() as [$group,$name,$icon]) {
            $exists = self::where('tenant_id',$tenantId)->where('name',$name)->exists();
            if (! $exists) {
                self::create(['tenant_id'=>$tenantId,'name'=>$name,'icon'=>$icon,'group'=>$group,'sort'=>$sort]);
                $n++;
            }
            $sort += 10;
        }
        return $n;
    }
}
