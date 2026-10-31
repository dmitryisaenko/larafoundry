<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Exceptions;

use RuntimeException;

/**
 * A translation came back but cannot be accepted - typically a marker the source
 * had is missing. The message is shown to the operator as is (a refusal, not a
 * failure of the editor).
 */
class HelpTranslationRefused extends RuntimeException {}
