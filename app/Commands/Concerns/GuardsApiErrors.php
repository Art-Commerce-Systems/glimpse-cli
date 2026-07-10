<?php

namespace App\Commands\Concerns;

use Closure;
use GlimpseImg\ApiException;
use GlimpseImg\AuthException;
use GlimpseImg\ValidationException;
use Illuminate\Http\Client\ConnectionException;

trait GuardsApiErrors
{
    /**
     * @param  Closure(): (int|null)  $callback
     */
    protected function runGuarded(Closure $callback): int
    {
        try {
            return $callback() ?? self::SUCCESS;
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  <fg=red>{$field}</>: {$message}");
                }
            }

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Could not reach the Glimpse API: '.$e->getMessage());

            return self::FAILURE;
        } catch (AuthException $e) {
            $this->error($e->getMessage().' Run: glimpse auth');

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
