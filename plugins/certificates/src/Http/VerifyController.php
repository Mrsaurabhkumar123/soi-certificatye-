<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Verification\PublicVerificationController;

/**
 * Public Verification HTTP Controller.
 * Delegates to PublicVerificationController while preserving backward compatibility.
 */
class VerifyController extends PublicVerificationController
{
}

