<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorksheetTemplateColumn extends Model
{
    protected $fillable = [
        'template_id', 'scope', 'key', 'type', 'stage', 'label_uz', 'label_ru', 'label_uz_cyrl',
        'unit', 'decimals', 'rounding', 'formula', 'default_value', 'required', 'element_id',
        'tolerance_pct', 'tolerance_source', 'tolerance_mode', 'width', 'sort_order',
    ];

    protected $casts = [
        'template_id' => 'integer',
        'element_id' => 'integer',
        'required'   => 'boolean',
        'decimals'   => 'integer',
        'width'      => 'integer',
        'sort_order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorksheetTemplate::class, 'template_id');
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class);
    }

    public function toDefinition(): array
    {
        return [
            'scope'            => $this->scope,
            'key'              => $this->key,
            'type'             => $this->type,
            'stage'            => $this->stage,
            'label_uz'         => $this->label_uz,
            'label_ru'         => $this->label_ru,
            'label_uz_cyrl'    => $this->label_uz_cyrl,
            'unit'             => $this->unit,
            'decimals'         => $this->decimals,
            'rounding'         => $this->rounding ?: 'round',
            'formula'          => $this->formula,
            'default_value'    => $this->default_value,
            'required'         => (bool) $this->required,
            'element_id'       => $this->element_id,
            'element_symbol'   => $this->element?->symbol,
            'tolerance_pct'    => $this->tolerance_pct !== null ? (string) (float) $this->tolerance_pct : null,
            'tolerance_source' => $this->tolerance_source,
            'tolerance_mode'   => $this->tolerance_mode ?: 'mean',
            'width'            => $this->width,
        ];
    }
}
