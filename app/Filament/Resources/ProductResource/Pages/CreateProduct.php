<?php
namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return static::foldImage($data);
    }

    public static function foldImage(array $data): array
    {
        $up = $data['image_upload'] ?? null;
        if (is_array($up)) $up = reset($up);
        if ($up) $data['image_url'] = $up;          // store the public-disk path; resolved on display
        unset($data['image_upload']);
        return $data;
    }
}
