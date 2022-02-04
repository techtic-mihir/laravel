<?php

namespace App\Data\Entities\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Data\Entities\Marketplace\Company as Vendor;

class Solution extends Model {

    use SoftDeletes;

    protected $connection = 'mysql2';

    protected $table = 'solution';

    protected $guarded = ['id'];

    protected $appends = [
        'solution_img',
        'color'
    ];


    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function type()
    {
        return $this->belongsTo(SolutionType::class, 'solution_type_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'company_id', 'id');
    }

	/**
	* The categories that belong to the solution.
	*/
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'solution_categories', 'solution_id', 'category_id')->isParent();
    }

    /**
    * The categories that belong to the solution.
    */
    public function subCategories()
    {
        return $this->belongsToMany(Category::class, 'solution_categories', 'solution_id', 'category_id')->isChild();
    }

    /**
    * The image that belong to the solution.
    */
    public function image()
    {
        return $this->belongsTo(Image::class, 'solution_logo_id', 'id');
    }

    /**
    * The ppt that belong to the solution.
    */
    public function ppt()
    {
        return $this->belongsTo(Image::class, 'ppt_video_id', 'id');
    }

    /**
    * Get the solutions image.
    *
    * @return string
    */
    public function getSolutionImgAttribute()
    {
        $logo = $this->image()->first();

        if ($this->is_company_logo) {
            $company_logo = $this->company()->first();
            if ($company_logo) {
                return $company_logo->logo;
            }
        } else if ($logo) {
            return $logo->image_location;
        }

        return null;
    }

    /**
    * Get the type.
    *
    * @return string
    */
    public function getColorAttribute()
    {
        $colors = ['success', 'danger', 'warning'];
        $random_keys = array_rand($colors, 3);
        return $colors[$random_keys[0]];
    }
}
