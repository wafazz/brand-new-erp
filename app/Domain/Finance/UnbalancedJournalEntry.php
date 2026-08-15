<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use RuntimeException;

class UnbalancedJournalEntry extends RuntimeException {}
