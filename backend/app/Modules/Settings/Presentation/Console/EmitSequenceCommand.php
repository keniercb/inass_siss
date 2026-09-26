<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Console;

use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use App\Modules\Shared\Exceptions\UnknownSequenceException;
use Illuminate\Console\Command;

/**
 * Sequence emission probe (plan S2.5): the concurrency test spawns N
 * parallel processes of this command to prove RN-009 under real
 * contention, and operations can use it as a health probe of the
 * numbering subsystem.
 *
 * Output contract: every emitted number is printed on its own line to
 * STDOUT (machine-parseable, no decoration) and the exit code is 0.
 * Undeclared scopes fail cleanly with exit code 1. Note that each
 * emitted number is burned forever (RN-009): running this command
 * consumes real sequence values.
 */
final class EmitSequenceCommand extends Command
{
    protected $signature = 'sequences:emit
        {scope : Sequence scope to emit from (e.g. bank_control)}
        {--times=1 : How many numbers to emit (integer between 1 and 100)}';

    protected $description = 'Emit numbers from a centralized sequence (they are burned: RN-009, never reused)';

    public function handle(SequenceGeneratorInterface $generator): int
    {
        $times = (int) $this->option('times');

        if ($times < 1 || $times > 100) {
            $this->error('The --times option must be an integer between 1 and 100.');

            return self::FAILURE;
        }

        $scope = (string) $this->argument('scope');

        if ($scope === '') {
            $this->error('The scope argument must not be empty.');

            return self::FAILURE;
        }

        try {
            for ($emitted = 0; $emitted < $times; $emitted++) {
                $this->line((string) $generator->next($scope));
            }
        } catch (UnknownSequenceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
