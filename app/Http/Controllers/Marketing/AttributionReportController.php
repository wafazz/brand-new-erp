<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Attribution\AttributionReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class AttributionReportController extends Controller
{
    public function __construct(private readonly AttributionReport $reports) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reports.view'), 403);

        $from = $this->date($request, 'from', now()->startOfMonth()->toDateString());
        $to = $this->date($request, 'to', now()->endOfMonth()->toDateString());

        return Inertia::render('Marketing/Attribution/Index', [
            'filters' => ['from' => $from, 'to' => $to],
            'campaigns' => $this->rows($this->reports->whichCampaignGeneratedRevenue($from, $to)),
            'marketers' => $this->rows($this->reports->whichMarketerGeneratedRevenue($from, $to)),
            'salespeople' => $this->rows($this->reports->whichSalespersonGeneratedRevenue($from, $to)),
            'channels' => $this->rows($this->reports->whichChannelConvertsBest($from, $to)),
            'spendVersusReturn' => $this->rows($this->reports->whatDidThisCampaignCostVersusReturn($from, $to)),
            'costPerLead' => $this->rows($this->reports->whatIsTheCostPerLeadByCampaign()),
            'branches' => $this->rows($this->reports->whichBranchGeneratedWhat($from, $to)),
        ]);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $rows): array
    {
        return $rows->map(static function (stdClass $row): array {
            $mapped = [];

            foreach (get_object_vars($row) as $key => $value) {
                $mapped[$key] = $value === null ? null : (string) $value;
            }

            return $mapped;
        })->all();
    }

    private function date(Request $request, string $key, string $fallback): string
    {
        $value = (string) $request->query($key, $fallback);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
