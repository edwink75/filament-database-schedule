<?php

namespace HusamTariq\FilamentDatabaseSchedule\Console\Scheduling;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use \Illuminate\Console\Scheduling\Schedule as BaseSchedule;
use HusamTariq\FilamentDatabaseSchedule\Models\ScheduleHistory;

use HusamTariq\FilamentDatabaseSchedule\Http\Services\ScheduleService;

class Schedule
{
    /**
     * @var BaseSchedule
     */
    private $schedule;

    private $tasks;

    private $history;

    public function __construct(ScheduleService $scheduleService, BaseSchedule $schedule)
    {
        $this->tasks = $scheduleService->getActives();
        $this->schedule = $schedule;
    }

    public function execute()
    {
        foreach ($this->tasks as $task) {
            $this->dispatch($task);
        }
    }

    /**
     * @throws \Exception
     */
    private function dispatch($task)
    {
        $model = config('filament-database-schedule.model');
        if ($task instanceof $model) {
            // @var Event $event
            if ($task->command === 'custom') {
                $command = $task->command_custom;
                $event = $this->schedule->exec($command);
            } else {
                $command = $task->command;
                $event = $this->schedule->command(
                    $command,
                    array_values($task->getArguments()) + $task->getOptions()
                );
            }
            $event->cron($task->expression);

            //ensure output is being captured to write history
            $event->storeOutput();

            if (!empty($task->environments)) {
                $event->environments($task->environments);
            }

            if ($task->even_in_maintenance_mode) {
                $event->evenInMaintenanceMode();
            }

            if ($task->without_overlapping) {
                $event->withoutOverlapping();
            }

            if ($task->run_in_background) {
                $event->runInBackground();
            }

            if (!empty($task->webhook_before)) {
                $event->pingBefore($task->webhook_before);
            }

            if (!empty($task->webhook_after)) {
                $event->thenPing($task->webhook_after);
            }

            if (!empty($task->email_output)) {
                if ($task->sendmail_success) {
                    $event->emailOutputTo($task->email_output);
                }

                if ($task->sendmail_error) {
                    $event->emailOutputOnFailure($task->email_output);
                }
            }

            if (!empty($task->on_one_server)) {
                $event->onOneServer();
            }

            $event->before(function () use ($task, $command) {
                $this->history = $this->createHistoryEntry($task, $command);
            });

            $event->onSuccess(
                function () use ($task, $event) {
                    $this->createLogFile($task, $event);
                    if ($task->log_success) {
                        $this->updateHistoryEntry($this->history, $event);
                    }
                }
            );

            $event->onFailure(
                function () use ($task, $event) {
                    $this->createLogFile($task, $event, 'critical');
                    if ($task->log_error) {
                        $this->updateHistoryEntry($this->history, $event);
                    }
                }
            );

            $event->after(function () use ($event, $task) {
                $text = $this->history ? $this->history->output : "";
                if ($task->sendmail_search_words && $this->foundSearchWords($task, $text)) {
                    $this->emailOutputForFoundSearchwords($task->email_output, $text, $event->command, $task->search_words);
                }
                unlink($event->output);
            });

            unset($event);
        } else {
            throw new \Exception('Task with invalid instance type');
        }
    }

    private function emailOutputForFoundSearchwords(string $receipients, string $text, string $command, string $search_words): void
    {
        $text = "Search words: " . join(", ", explode("\n", $search_words)) . "\n\nOutput:\n" . $text;

        dispatch(function () use ($text, $receipients, $command) {
            $subject = ($command != "" ? $command . ": " : "") . "One or more of defined search words have been found";
            foreach (explode(",", $receipients) as $receipient) {
                Mail::raw($text, function ($message) use ($receipient, $subject) {
                    $message->to($receipient);
                    $message->subject($subject);
                });
            }
        });
    }

    private function foundSearchWords($task, $text): bool
    {
        if (Str::contains($text, explode("\n", $task->search_words))) {
            return true;
        }
        return false;
    }

    private function createLogFile($task, $event, $type = 'info')
    {
        if ($task->log_filename) {
            $logChannel = Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/' . $task->log_filename . '.log'),
            ]);
            Log::stack([$logChannel])->$type(file_get_contents($event->output));
        }
    }

    private function createHistoryEntry($task, $command): ScheduleHistory
    {
        return $task->histories()->create(
            [
                'command' => $command,
                'params' => $task->getArguments(),
                'options' => $task->getOptions(),
                'output' => "Processing..."
            ]
        );
    }

    private function updateHistoryEntry(ScheduleHistory $history, $event)
    {
        $history->update(
            [
                'output' => file_get_contents($event->output)
            ]
        );
    }
}
