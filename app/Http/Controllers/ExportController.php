<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exporting\CsvWriter;
use App\Domain\Exporting\ExportDefinition;
use App\Domain\Exporting\ExportRegistry;
use App\Models\User;
use App\Services\Access\ScopeResolver;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly CsvWriter $csv,
        private readonly AuditRecorder $recorder,
        private readonly ScopeResolver $scopes,
    ) {}

    public function download(Request $request, string $key): StreamedResponse
    {
        $definition = $this->registry->find($key);

        abort_if($definition === null, 404);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can($definition->ability), 403, "You may not export {$definition->label}.");

        $query = $this->query($definition, $user);

        $rows = (clone $query)->count();

        $this->recorder->record(
            'exported',
            $definition->key,
            null,
            $user,
            "Exported {$rows} row(s) of {$definition->label} as CSV."
        );

        return response()->streamDownload(
            function () use ($definition, $query): void {
                $this->csv->open($definition->headings(), $this->rows($definition, $query));
            },
            $definition->filename(),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return Builder<Model>
     */
    private function query(ExportDefinition $definition, User $user): Builder
    {
        /** @var Builder<Model> $query */
        $query = $definition->model::query();

        if ($definition->scopeAbility !== null) {
            $query = $this->scopes->apply($query, $user, $definition->scopeAbility);
        }

        if ($definition->with !== []) {
            $query->with($definition->with);
        }

        return $query->orderBy($definition->orderBy, $definition->direction);
    }

    /**
     * @param  Builder<Model>  $query
     * @return iterable<int, array<int, string>>
     */
    private function rows(ExportDefinition $definition, Builder $query): iterable
    {
        foreach ($query->lazy(500) as $record) {
            $row = [];

            foreach ($definition->columns as $resolve) {
                $row[] = (string) $resolve($record);
            }

            yield $row;
        }
    }
}
