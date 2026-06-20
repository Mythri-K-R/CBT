<?php

namespace App\Events;

use App\Models\QuestionImport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuestionsImported
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly QuestionImport $import) {}
}
