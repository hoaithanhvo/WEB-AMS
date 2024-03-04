<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Transformers\CategoriesTransformer;
use App\Http\Transformers\SelectlistTransformer;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Requests\ImageUploadRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use function Amp\Iterator\toArray;

class CategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->authorize('view', Category::class);
        $allowed_columns = [
            'id',
            'name',
            'category_type',
            'category_type',
            'use_default_eula',
            'eula_text',
            'require_acceptance',
            'checkin_email',
            'assets_count',
            'accessories_count',
            'consumables_count',
            'components_count',
            'licenses_count',
            'image',
        ];

        $categories = Category::select([
            'id',
            'created_at',
            'updated_at',
            'name', 'category_type',
            'use_default_eula',
            'eula_text',
            'require_acceptance',
            'checkin_email',
            'image'
            ])->withCount('accessories as accessories_count', 'consumables as consumables_count', 'components as components_count', 'licenses as licenses_count');


        /*
         * This checks to see if we should override the Admin Setting to show archived assets in list.
         * We don't currently use it within the Snipe-IT GUI, but will be useful for API integrations where they
         * may actually need to fetch assets that are archived.
         *
         * @see \App\Models\Category::showableAssets()
         */
        if ($request->input('archived')=='true') {
            $categories = $categories->withCount('assets as assets_count');
        } else {
            $categories = $categories->withCount('showableAssets as assets_count');
        }

        if ($request->filled('search')) {
            $categories = $categories->TextSearch($request->input('search'));
        }

        if ($request->filled('name')) {
            $categories->where('name', '=', $request->input('name'));
        }

        if ($request->filled('category_type')) {
            $categories->where('category_type', '=', $request->input('category_type'));
        }

        if ($request->filled('use_default_eula')) {
            $categories->where('use_default_eula', '=', $request->input('use_default_eula'));
        }

        if ($request->filled('require_acceptance')) {
            $categories->where('require_acceptance', '=', $request->input('require_acceptance'));
        }

        if ($request->filled('checkin_email')) {
            $categories->where('checkin_email', '=', $request->input('checkin_email'));
        }

        // Make sure the offset and limit are actually integers and do not exceed system limits
        $offset = ($request->input('offset') > $categories->count()) ? $categories->count() : abs($request->input('offset'));
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'assets_count';
        $categories->orderBy($sort, $order);

        $total = $categories->count();
        $categories = $categories->skip($offset)->take($limit)->get();

        return (new CategoriesTransformer)->transformCategories($categories, $total);

    }


    /**
     * Store a newly created resource in storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @param  \App\Http\Requests\ImageUploadRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(ImageUploadRequest $request)
    {
        $this->authorize('create', Category::class);
        $category = new Category;
        $category->fill($request->all());
        $category->category_type = strtolower($request->input('category_type'));
        $category = $request->handleImages($category);

        if ($category->save()) {
            return response()->json(Helper::formatStandardApiResponse('success', $category, trans('admin/categories/message.create.success')));
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, $category->getErrors()));

    }

    /**
     * Display the specified resource.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $this->authorize('view', Category::class);
        $category = Category::withCount('assets as assets_count', 'accessories as accessories_count', 'consumables as consumables_count', 'components as components_count', 'licenses as licenses_count')->findOrFail($id);
        return (new CategoriesTransformer)->transformCategory($category);

    }


    /**
     * Update the specified resource in storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @param  \App\Http\Requests\ImageUploadRequest  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ImageUploadRequest $request, $id)
    {
        $this->authorize('update', Category::class);
        $category = Category::findOrFail($id);

        // Don't allow the user to change the category_type once it's been created
        if (($request->filled('category_type')) && ($category->category_type != $request->input('category_type'))) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null,  trans('admin/categories/message.update.cannot_change_category_type'))
            );
        }
        $category->fill($request->all());
        $category = $request->handleImages($category);

        if ($category->save()) {
            return response()->json(Helper::formatStandardApiResponse('success', $category, trans('admin/categories/message.update.success')));
        }

        return response()->json(Helper::formatStandardApiResponse('error', null, $category->getErrors()));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0]
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->authorize('delete', Category::class);
        $category = Category::withCount('assets as assets_count', 'accessories as accessories_count', 'consumables as consumables_count', 'components as components_count', 'licenses as licenses_count')->findOrFail($id);

        if (! $category->isDeletable()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/categories/message.assoc_items', ['asset_type'=>$category->category_type]))
            );
        }
        $category->delete();

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/categories/message.delete.success')));
    }


    /**
     * Gets a paginated collection for the select2 menus
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.0.16]
     * @see \App\Http\Transformers\SelectlistTransformer
     */
    public function selectlist(Request $request, $category_type = 'asset')
    {
        $this->authorize('view.selectlists');
        $categories = Category::select([
            'id',
            'name',
            'image',
        ]);

        if ($request->filled('search')) {
            $categories = $categories->where('name', 'LIKE', '%'.$request->get('search').'%');
        }

        $categories = $categories->where('category_type', $category_type)->orderBy('name', 'ASC')->paginate(50);

        // Loop through and set some custom properties for the transformer to use.
        // This lets us have more flexibility in special cases like assets, where
        // they may not have a ->name value but we want to display something anyway
        foreach ($categories as $category) {
            $category->use_image = ($category->image) ? Storage::disk('public')->url('categories/'.$category->image, $category->image) : null;
        }

        return (new SelectlistTransformer)->transformSelectlist($categories);
    }

    /**
     * Get all field by Category id
     *
     * @return \Illuminate\Http\Response
     */
    public function getFieldsByCategoryId($id)
    {

        if (!Gate::allows('self.api')) {
            abort(403);
        }

        $arrayFields = [
            // [
            //     'name' => 'Id',
            //     'db_column' => 'id'
            // ],
            [
                'name' => 'Asset Tag',
                'db_column' => 'asset_tag'
            ],
            [
                'name' => 'Model',
                'db_column' => 'model'
            ],
            [
                'name' => 'Serial Number',
                'db_column' => 'serial'
            ],
            [
                'name' => 'Asset Name',
                'db_column' => 'name'
            ],
            [
                'name' => 'Model Number',
                'db_column' => 'model_number'
            ],
            // [
            //     'name' => 'End of Life',
            //     'db_column' => 'eol'
            // ],
            // [
            //     'name' => 'Asset End of Life Date',
            //     'db_column' => 'asset_eol_date'
            // ],
            [
                'name' => 'Status Label',
                'db_column' => 'status_label'
            ],
            [
                'name' => 'Category',
                'db_column' => 'category'
            ],
            [
                'name' => 'Manufacturer',
                'db_column' => 'manufacturer'
            ],
            [
                'name' => 'Supplier',
                'db_column' => 'supplier'
            ],
            [
                'name' => 'Notes',
                'db_column' => 'notes'
            ],
            [
                'name' => 'Order Number',
                'db_column' => 'order_number'
            ],
            [
                'name' => 'Company',
                'db_column' => 'company'
            ],
            [
                'name' => 'Location',
                'db_column' => 'location'
            ],
            [
                'name' => 'Default Location',
                'db_column' => 'rtd_location'
            ],
            // [
            //     'name' => 'Image',
            //     'db_column' => 'image'
            // ],
            // [
            //     'name' => 'QR Code',
            //     'db_column' => 'qr'
            // ],
            // [
            //     'name' => 'Alternate Barcode',
            //     'db_column' => 'alt_barcode'
            // ],
            [
                'name' => 'Assigned To',
                'db_column' => 'assigned_to'
            ],
            [
                'name' => 'Warranty Months',
                'db_column' => 'warranty_months'
            ],
            [
                'name' => 'Warranty Expires',
                'db_column' => 'warranty_expires'
            ],
            [
                'name' => 'Created At',
                'db_column' => 'created_at'
            ],
            [
                'name' => 'Updated At',
                'db_column' => 'updated_at'
            ],
            [
                'name' => 'Last Audit Date',
                'db_column' => 'last_audit_date'
            ],
            [
                'name' => 'Next Audit Date',
                'db_column' => 'next_audit_date'
            ],
            // [
            //     'name' => 'Deleted At',
            //     'db_column' => 'deleted_at'
            // ],
            [
                'name' => 'Purchase Date',
                'db_column' => 'purchase_date'
            ],
            [
                'name' => 'Age',
                'db_column' => 'age'
            ],
            [
                'name' => 'Last Checkout',
                'db_column' => 'last_checkout'
            ],
            [
                'name' => 'Expected Checkin',
                'db_column' => 'expected_checkin'
            ],
            [
                'name' => 'Purchase Cost',
                'db_column' => 'purchase_cost'
            ],
            [
                'name' => 'Checkin Counter',
                'db_column' => 'checkin_counter'
            ],
            [
                'name' => 'Checkout Counter',
                'db_column' => 'checkout_counter'
            ],
            [
                'name' => 'Requests Counter',
                'db_column' => 'requests_counter'
            ],
            // [
            //     'name' => 'User Can Checkout',
            //     'db_column' => 'user_can_checkout'
            // ],
            // [
            //     'name' => 'Book Value',
            //     'db_column' => 'book_value'
            // ]
        ];

        $defaultFields = [
            'model',
            'serial',
            'name',
            'notes',
            'location'
        ];

        $fieldsShow = DB::table('category_fields')
                    ->select('db_column')
                    ->where('category_id', '=', $id)
                    ->get();

        foreach ($arrayFields as &$field) {
            $field['is_displayed'] = 0;
            if ($fieldsShow->contains('db_column', $field['db_column']) ||
            in_array($field['db_column'], $defaultFields) && $fieldsShow->isEmpty()) {
                $field['is_displayed'] = 1;
            }
        }
        
        // get custom fields by category id
        $customFields = DB::table('custom_fields as cf')
        ->select('cf.name', 'cf.db_column', DB::raw('CASE WHEN ctgf.category_id IS NOT NULL THEN true ELSE false END as is_displayed'))
        ->join('custom_field_custom_fieldset as cfcfs', 'cfcfs.custom_field_id', '=', 'cf.id')
        ->join('custom_fieldsets as cfs', 'cfs.id', '=', 'cfcfs.custom_fieldset_id')
        ->join('models as md', 'cfs.id', '=', 'md.fieldset_id')
        ->leftJoin('category_fields as ctgf', function ($join) {
            $join->on('ctgf.category_id', '=', 'md.category_id')
                 ->on('ctgf.db_column', '=', 'cf.db_column');
        })
        ->where('md.category_id', '=', $id)
        ->distinct()
        ->get();

        $result = array_merge($arrayFields, $customFields->toArray());

        return response()->json(Helper::formatStandardApiResponse('success', $result));
    }


    /**
     * Update field allowed display by category id
     *
     * @return \Illuminate\Http\Response
     */
    public function updateFieldAllowedDisplay(Request $request)
    {
        if (!Gate::allows('self.api')) {
            abort(403);
        }

        $listFieldString = $request->input("list_field");
        $listFieldString = str_replace(['[', ']'], '', $listFieldString);
        $arrayField = explode(', ', $listFieldString);

        $categoryId = $request->input("category_id");

        // reset settings to default
        DB::table('category_fields')->where('category_id', $categoryId)->delete();

        // add new settings
        foreach ($arrayField as $field) {
            DB::table('category_fields')->insert([
                'db_column' => $field,
                'category_id' => $categoryId
            ]);
        }

        return response()->json(Helper::formatStandardApiResponse('success'));
    }

    /**
     * Get fields all Category
     *
     * @return \Illuminate\Http\Response
     */
    public function getFieldsAllCategory()
    {
        // Define array to map column names to their respective field names
        $columnToName = [
            [
                'name' => 'Id',
                'db_column' => 'id'
            ],
            [
                'name' => 'Asset Tag',
                'db_column' => 'asset_tag'
            ],
            [
                'name' => 'Model',
                'db_column' => 'model'
            ],
            [
                'name' => 'Serial Number',
                'db_column' => 'serial'
            ],
            [
                'name' => 'Asset Name',
                'db_column' => 'name'
            ],
            [
                'name' => 'Model Number',
                'db_column' => 'model_number'
            ],
            [
                'name' => 'End of Life',
                'db_column' => 'eol'
            ],
            [
                'name' => 'Asset End of Life Date',
                'db_column' => 'asset_eol_date'
            ],
            [
                'name' => 'Status Label',
                'db_column' => 'status_label'
            ],
            [
                'name' => 'Category',
                'db_column' => 'category'
            ],
            [
                'name' => 'Manufacturer',
                'db_column' => 'manufacturer'
            ],
            [
                'name' => 'Supplier',
                'db_column' => 'supplier'
            ],
            [
                'name' => 'Notes',
                'db_column' => 'notes'
            ],
            [
                'name' => 'Order Number',
                'db_column' => 'order_number'
            ],
            [
                'name' => 'Company',
                'db_column' => 'company'
            ],
            [
                'name' => 'Location',
                'db_column' => 'location'
            ],
            [
                'name' => 'Default Location',
                'db_column' => 'rtd_location'
            ],
            [
                'name' => 'Image',
                'db_column' => 'image'
            ],
            [
                'name' => 'QR Code',
                'db_column' => 'qr'
            ],
            [
                'name' => 'Alternate Barcode',
                'db_column' => 'alt_barcode'
            ],
            [
                'name' => 'Assigned To',
                'db_column' => 'assigned_to'
            ],
            [
                'name' => 'Warranty Months',
                'db_column' => 'warranty_months'
            ],
            [
                'name' => 'Warranty Expires',
                'db_column' => 'warranty_expires'
            ],
            [
                'name' => 'Created At',
                'db_column' => 'created_at'
            ],
            [
                'name' => 'Updated At',
                'db_column' => 'updated_at'
            ],
            [
                'name' => 'Last Audit Date',
                'db_column' => 'last_audit_date'
            ],
            [
                'name' => 'Next Audit Date',
                'db_column' => 'next_audit_date'
            ],
            [
                'name' => 'Deleted At',
                'db_column' => 'deleted_at'
            ],
            [
                'name' => 'Purchase Date',
                'db_column' => 'purchase_date'
            ],
            [
                'name' => 'Age',
                'db_column' => 'age'
            ],
            [
                'name' => 'Last Checkout',
                'db_column' => 'last_checkout'
            ],
            [
                'name' => 'Expected Checkin',
                'db_column' => 'expected_checkin'
            ],
            [
                'name' => 'Purchase Cost',
                'db_column' => 'purchase_cost'
            ],
            [
                'name' => 'Checkin Counter',
                'db_column' => 'checkin_counter'
            ],
            [
                'name' => 'Checkout Counter',
                'db_column' => 'checkout_counter'
            ],
            [
                'name' => 'Requests Counter',
                'db_column' => 'requests_counter'
            ],
            [
                'name' => 'User Can Checkout',
                'db_column' => 'user_can_checkout'
            ],
            [
                'name' => 'Book Value',
                'db_column' => 'book_value'
            ]
        ];

        // Define default fields to be used if no fields are displayed for a category
        $defaultFields = [
            [
                'name' => 'Model',
                'db_column' => 'model'
            ],
            [
                'name' => 'Serial Number',
                'db_column' => 'serial'
            ],
            [
                'name' => 'Asset Name',
                'db_column' => 'name'
            ],
            [
                'name' => 'Model Number',
                'db_column' => 'model_number'
            ],
            [
                'name' => 'Location',
                'db_column' => 'location'
            ],
        ];

        // Retrieve list of categories that are not deleted
        $categoryList = DB::table('categories')
                        ->select('id')
                        ->whereNull('deleted_at')
                        ->get();

        // Retrieve displayed fields for each category
        $displayedFields = DB::table('category_fields as ctf')
        ->leftJoin('custom_fields as cf', 'cf.db_column', '=', 'ctf.db_column')
        ->select('ctf.category_id', 'ctf.db_column', 'cf.name')
        ->get();

        $result = [];

        foreach ($categoryList as $category) {
            $categoryId = $category->id;
            $found = false;
            
            foreach ($displayedFields as $field) {
                // Check if the field belongs to the current category
                if ($field->category_id == $categoryId) {
                    // If the field name is null, look it up in the columnToName mapping
                    if (is_null($field->name)) {
                        $foundField = collect($columnToName)->where('db_column', $field->db_column)->first();
        
                        if ($foundField) {
                            $fieldName = $foundField['name'];
                        } else {
                            $fieldName = $field->db_column;
                        }
                    } else {
                        $fieldName = $field->name;
                    }
                    // Add the field to the result array
                    $result[$categoryId][] = ['name' => $fieldName, 'db_column' => $field->db_column];
                    $found = true;
                }
            }
            
            // If no displayed fields were found for the category, use default fields
            if (!$found) {
                $result[$categoryId] = $defaultFields;
            }
        }

        // Return JSON response with the result
        return response()->json(Helper::formatStandardApiResponse('success', $result));
    }

}
