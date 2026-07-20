<?php

namespace MathiasGrimm\GlimpseCli\Commands\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use MathiasGrimm\GlimpsePhp\ApiException;
use MathiasGrimm\GlimpsePhp\AuthException;
use MathiasGrimm\GlimpsePhp\ForbiddenException;
use MathiasGrimm\GlimpsePhp\RateLimitException;
use MathiasGrimm\GlimpsePhp\ValidationException;

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
            // A rejected built-in token means this CLI build carries a
            // rotated-out token; the hint points at the way forward.
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (RateLimitException $e) {
            $this->error($e->getMessage().($e->retryAfterSeconds !== null
                ? sprintf(' Retry after %d seconds.', $e->retryAfterSeconds)
                : ''));
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (ForbiddenException $e) {
            $this->error($e->getMessage());
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * When the failed request went out with the built-in public CI token,
     * point at the real fix: a personal token.
     */
    private function publicTokenHint(): void
    {
        if (! app(Config::class)->usingPublicToken()) {
            return;
        }

        $this->line('You are using the built-in public CI token. It only runs check and analyze, and its rate limits are shared. Get your own free token at https://glimpseimg.com and set GLIMPSE_TOKEN for higher limits and your own usage dashboard.');
    }
}
