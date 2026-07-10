<?php

namespace App\Enums;

/**
 * How much authority the AI has for a given monitor or workspace.
 *
 *   - Off:     the model does nothing.
 *   - Suggest: the model writes inbox suggestions, humans act.
 *   - Auto:    the model opens, updates, and resolves incidents on its own.
 */
enum AiMode: string
{
    case Off = 'off';
    case Suggest = 'suggest';
    case Auto = 'auto';
}
