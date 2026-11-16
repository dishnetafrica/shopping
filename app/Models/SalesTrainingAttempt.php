<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One assessment sitting (written only by App\Services\Sales\Training\SalesTraining). */
class SalesTrainingAttempt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'status', 'questions', 'quiz_versions', 'answers', 'module_scores', 'score', 'passed', 'started_at', 'submitted_at'];

    protected $casts = [
        'questions' => 'array', 'quiz_versions' => 'array', 'answers' => 'array', 'module_scores' => 'array',
        'score' => 'float', 'passed' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime',
    ];
}
