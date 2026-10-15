<?php

namespace EduLazaro\Laratext;

use RuntimeException;

/**
 * A model stopped answering because it reached its output limit, so its JSON is cut short.
 *
 * Sending the same batch again would be cut at the same place and paid for again, so the
 * batch is split instead (`Translator::batchTranslate()`).
 */
class ResponseTruncated extends RuntimeException
{
}
