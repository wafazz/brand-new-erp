<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use RuntimeException;

class ApprovalNotPermitted extends RuntimeException {}
