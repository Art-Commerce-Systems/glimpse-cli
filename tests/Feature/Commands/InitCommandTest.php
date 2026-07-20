<?php

use App\Commands\InitCommand;
use App\Support\BaselineFile;
use App\Support\IgnoreFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

const INIT_SEED_QUESTION = 'Scan the current directory and record every image into the baseline now (runs analyze . --update-baseline)?';

const INIT_WORKFLOW_QUESTION = 'Add a GitHub Actions workflow that runs glimpse check on pull requests and pushes to main (.github/workflows/glimpse.yml)?';

const INIT_EMPTY_BASELINE_WARNING = 'The baseline is empty but the workflow gates images, so the first CI run will re-check every image in the repository and fail on any that would benefit from optimization. Seed the baseline before pushing: glimpse analyze . --update-baseline';

function ignorePath(): string
{
    return workspace().'/'.IgnoreFile::FILENAME;
}

function workflowPath(): string
{
    return workspace().'/'.InitCommand::WORKFLOW_PATH;
}

/**
 * Plant an existing workflow file with recognizable non-template content
 * in the directory (the test workspace by default).
 */
function writeWorkflow(string $content = "custom: workflow\n", ?string $directory = null): string
{
    $path = ($directory ?? workspace()).'/'.InitCommand::WORKFLOW_PATH;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $content);

    return $content;
}

test('scaffolds the starter ignore file and an empty baseline with zero prerequisites', function () {
    chdirWorkspace();
    Http::fake();

    expect(Artisan::call('init'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Created '.IgnoreFile::FILENAME.'.')
        ->and($output)->toContain('Created '.BaselineFile::FILENAME.' (empty).');

    $ignore = (string) file_get_contents(ignorePath());

    expect($ignore)->toContain('vendor/')
        ->and($ignore)->toContain('node_modules/')
        ->and($ignore)->toContain('storage/')
        ->and($ignore)->toContain('apple-touch-icon*.png');

    $baseline = json_decode((string) file_get_contents(baselinePath()), true);

    expect($baseline['_readme'])->toBe(BaselineFile::README)
        ->and($baseline['files'])->toBe([]);

    Http::assertNothingSent();
});

test('confirming the prompt seeds the baseline through analyze', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    $this->artisan('init')
        ->expectsConfirmation(INIT_SEED_QUESTION, 'yes')
        ->assertExitCode(0);

    expect(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);

    Http::assertSentCount(1);
});

test('declining the prompt writes an empty baseline without touching the API', function () {
    chdirWorkspace();
    createImage('photo.png');
    Http::fake();

    $this->artisan('init')
        ->expectsConfirmation(INIT_SEED_QUESTION)
        ->assertExitCode(0);

    expect(baselineFiles())->toBe([]);

    Http::assertNothingSent();
});

test('--update-baseline seeds without prompting', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0)
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);
});

test('the ignore file is written before the seed scan, so the template already applies', function () {
    chdirWorkspace();
    createImage('vendor/lib/pic.png');
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0)
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);

    Http::assertSentCount(1);
});

test('re-running on a configured project keeps both files and exits 0', function () {
    chdirWorkspace();
    Http::fake();
    Artisan::call('init');

    $ignoreBefore = (string) file_get_contents(ignorePath());
    $baselineBefore = (string) file_get_contents(baselinePath());

    $this->artisan('init')
        ->expectsOutputToContain(IgnoreFile::FILENAME.' already exists, kept (use --force to recreate it from the starter template).')
        ->expectsOutputToContain(BaselineFile::FILENAME.' already exists, kept.')
        ->assertExitCode(0);

    expect((string) file_get_contents(ignorePath()))->toBe($ignoreBefore)
        ->and((string) file_get_contents(baselinePath()))->toBe($baselineBefore);

    Http::assertNothingSent();
});

test('creates only the missing file when the other already exists', function () {
    chdirWorkspace();
    Http::fake();
    file_put_contents(ignorePath(), "custom-pattern/\n");

    expect(Artisan::call('init'))->toBe(0)
        ->and((string) file_get_contents(ignorePath()))->toBe("custom-pattern/\n")
        ->and(baselineFiles())->toBe([]);

    Http::assertNothingSent();
});

test('--force recreates the ignore file from the template and leaves a populated baseline untouched', function () {
    chdirWorkspace();
    Http::fake();
    file_put_contents(ignorePath(), "hand-edited/\n");
    $entry = baselineEntry(createImage('photo.png'));
    writeBaseline(['photo.png' => $entry]);

    expect(Artisan::call('init', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Recreated '.IgnoreFile::FILENAME.' from the starter template.');

    $ignore = (string) file_get_contents(ignorePath());

    expect($ignore)->toContain('vendor/')
        ->and($ignore)->not->toContain('hand-edited/')
        ->and(baselineFiles())->toBe(['photo.png' => $entry]);

    Http::assertNothingSent();
});

test('--update-baseline refreshes an existing baseline through analyze without prompting', function () {
    chdirWorkspace();
    $path = createImage('photo.png');
    $stale = baselineEntry($path);
    $stale['xxh128'] = 'stale';
    writeBaseline(['photo.png' => $stale, 'gone.png' => ['size' => 1, 'xxh128' => 'x', 'via' => 'analyze']]);
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    $this->artisan('init', ['--update-baseline' => true])
        ->assertExitCode(0);

    expect(baselineFiles())->toBe(['photo.png' => baselineEntry($path)]);
});

test('a failed seed still scaffolds an empty baseline and exits 1 with a retry hint', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline')
        ->and(is_file(ignorePath()))->toBeTrue()
        ->and(baselineFiles())->toBe([]);
});

test('a partially failed seed passes analyze exit 0 through and drops the seed hint', function () {
    chdirWorkspace();
    createImage('photo.png');
    createImage('broken.png', 'not an image');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('skipped: Unrecognized image format.')
        ->and($output)->not->toContain('Accept the current images as already handled')
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);
});

test('the seed-hint state does not leak between runs in the same process', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    Artisan::call('init', ['--update-baseline' => true]);
    Artisan::output();

    chdirWorkspace(workspace().'/fresh');

    expect(Artisan::call('init'))->toBe(0)
        ->and(Artisan::output())->toContain('Accept the current images as already handled');
});

test('next steps include the seed hint on a scaffold-only run', function () {
    chdirWorkspace();
    Http::fake();

    Artisan::call('init');
    $output = Artisan::output();

    expect($output)->toContain('Next steps:')
        ->and($output)->toContain('Accept the current images as already handled: glimpse analyze . --update-baseline')
        ->and($output)->toContain('Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.')
        ->and($output)->toContain('glimpse check .');
});

test('next steps drop the seed hint when the baseline was seeded', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    Artisan::call('init', ['--update-baseline' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Next steps:')
        ->and($output)->not->toContain('Accept the current images as already handled');
});

describe('workflow scaffolding', function () {
    test('a git repository is offered the workflow, and confirming scaffolds it', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');
        Http::fake();

        $this->artisan('init')
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->expectsConfirmation(INIT_WORKFLOW_QUESTION, 'yes')
            ->expectsOutputToContain('Created '.InitCommand::WORKFLOW_PATH.'.')
            ->assertExitCode(0);

        expect((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE);

        Http::assertNothingSent();
    });

    test('declining the workflow prompt writes nothing and keeps the generic CI hint', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');
        Http::fake();

        $this->artisan('init')
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->expectsConfirmation(INIT_WORKFLOW_QUESTION)
            ->expectsOutputToContain('Gate new images in CI: glimpse check .')
            ->assertExitCode(0);

        expect(is_file(workflowPath()))->toBeFalse();
    });

    test('outside a git repository there is no workflow prompt and no file', function () {
        chdirWorkspace();
        Http::fake();

        // Only the seed confirmation is expected; an unexpected workflow
        // confirm would fail this interactive run.
        $this->artisan('init')
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->assertExitCode(0);

        expect(is_file(workflowPath()))->toBeFalse();
    });

    test('a worktree-style .git file also triggers the offer', function () {
        chdirWorkspace();
        file_put_contents(workspace().'/.git', "gitdir: ../elsewhere\n");
        Http::fake();

        $this->artisan('init')
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->expectsConfirmation(INIT_WORKFLOW_QUESTION, 'yes')
            ->assertExitCode(0);

        expect((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE);
    });

    test('non-interactive runs fall through to the default and never write the workflow', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');
        Http::fake();

        expect(Artisan::call('init'))->toBe(0)
            ->and(is_file(workflowPath()))->toBeFalse()
            ->and(Artisan::output())->toContain('Gate new images in CI: glimpse check .');
    });

    test('--workflow scaffolds without prompting and without a git repository', function () {
        chdirWorkspace();
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(0);

        $output = Artisan::output();

        expect($output)->toContain('Created '.InitCommand::WORKFLOW_PATH.'.')
            ->and($output)->toContain('Optional: set the GLIMPSE_TOKEN secret for higher rate limits and usage attribution: gh secret set GLIMPSE_TOKEN')
            ->and($output)->toContain('Commit '.IgnoreFile::FILENAME.', '.BaselineFile::FILENAME.', and '.InitCommand::WORKFLOW_PATH.'.')
            ->and($output)->not->toContain('Gate new images in CI')
            ->and((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE);
    });

    test('an existing workflow is kept byte for byte, without a prompt', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');
        $content = writeWorkflow();
        Http::fake();

        // No workflow confirmation is registered: the existing file must
        // short-circuit the prompt even in a git repository.
        $this->artisan('init')
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->expectsOutputToContain(InitCommand::WORKFLOW_PATH.' already exists, kept (use --workflow --force to recreate it).')
            ->expectsOutputToContain('Review '.InitCommand::WORKFLOW_PATH.'; the GLIMPSE_TOKEN secret is optional but gives higher rate limits: gh secret set GLIMPSE_TOKEN')
            ->assertExitCode(0);

        expect((string) file_get_contents(workflowPath()))->toBe($content);
    });

    test('--workflow keeps an existing file unless --force is added', function () {
        chdirWorkspace();
        $content = writeWorkflow();
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('already exists, kept (use --workflow --force to recreate it).')
            ->and((string) file_get_contents(workflowPath()))->toBe($content);
    });

    test('--workflow --force recreates an existing workflow from the template', function () {
        chdirWorkspace();
        writeWorkflow();
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true, '--force' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Recreated '.InitCommand::WORKFLOW_PATH.' from the starter template.')
            ->and((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE);
    });

    test('--force alone never touches an existing workflow', function () {
        chdirWorkspace();
        $content = writeWorkflow();
        Http::fake();

        expect(Artisan::call('init', ['--force' => true]))->toBe(0)
            ->and((string) file_get_contents(workflowPath()))->toBe($content);
    });

    test('a failed baseline seed still scaffolds the workflow and exits 1', function () {
        chdirWorkspace();
        createImage('photo.png');
        putenv('GLIMPSE_TOKEN=test-token');
        Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        expect(Artisan::call('init', ['--update-baseline' => true, '--workflow' => true]))->toBe(1)
            ->and((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE);
    });

    test('a .github regular file fails the run cleanly', function () {
        chdirWorkspace();
        file_put_contents(workspace().'/.github', 'not a directory');
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Could not create the directory')
            ->and(is_file(workflowPath()))->toBeFalse();
    });

    test('a symlinked workflow is refused and its target preserved', function () {
        chdirWorkspace();
        mkdir(dirname(workflowPath()), 0755, true);
        file_put_contents(workspace().'/target.yml', "original\n");
        symlink(workspace().'/target.yml', workflowPath());
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true, '--force' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('is a symbolic link')
            ->and((string) file_get_contents(workspace().'/target.yml'))->toBe("original\n");
    });

    test('the workflow state does not leak between runs in the same process', function () {
        chdirWorkspace();
        Http::fake();

        Artisan::call('init', ['--workflow' => true]);
        Artisan::output();

        // The written state must not leak into a project whose workflow
        // already exists, and the kept state set here must not leak out.
        $kept = workspace().'/kept';
        writeWorkflow(directory: $kept);
        chdirWorkspace($kept);

        Artisan::call('init');
        $output = Artisan::output();

        expect($output)->toContain('Review '.InitCommand::WORKFLOW_PATH)
            ->and($output)->not->toContain('set the GLIMPSE_TOKEN secret for higher rate limits');

        chdirWorkspace(workspace().'/fresh');

        Artisan::call('init');
        $output = Artisan::output();

        expect($output)->toContain('Gate new images in CI')
            ->and($output)->not->toContain('gh secret set');
    });

    test('--workflow suppresses only the workflow prompt, the seed prompt still appears', function () {
        chdirWorkspace();
        createImage('photo.png');
        putenv('GLIMPSE_TOKEN=test-token');
        Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

        $this->artisan('init', ['--workflow' => true])
            ->expectsConfirmation(INIT_SEED_QUESTION, 'yes')
            ->assertExitCode(0);

        expect((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE)
            ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);
    });

    test('--force alone still only offers a missing workflow, it does not select it', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');
        Http::fake();

        $this->artisan('init', ['--force' => true])
            ->expectsConfirmation(INIT_SEED_QUESTION)
            ->expectsConfirmation(INIT_WORKFLOW_QUESTION)
            ->assertExitCode(0);

        expect(is_file(workflowPath()))->toBeFalse();
    });

    test('a .github/workflows regular file fails the run cleanly', function () {
        chdirWorkspace();
        mkdir(workspace().'/.github');
        file_put_contents(workspace().'/.github/workflows', 'not a directory');
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Could not create the directory')
            ->and((string) file_get_contents(workspace().'/.github/workflows'))->toBe('not a directory');
    });

    test('a symlinked workflows directory is refused, nothing lands at its target', function () {
        chdirWorkspace();
        mkdir(workspace().'/.github');
        mkdir(workspace().'/elsewhere');
        symlink(workspace().'/elsewhere', workspace().'/.github/workflows');
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('is a symbolic link')
            ->and(glob(workspace().'/elsewhere/*'))->toBe([]);
    });

    test('piped answers reach the prompts in order', function () {
        chdirWorkspace();
        mkdir(workspace().'/.git');

        // A real subprocess with real stdin: "no" answers the seed
        // question (so no API call is attempted), "yes" the workflow one.
        $process = new Process([PHP_BINARY, base_path('glimpse'), 'init'], workspace());
        $process->setInput("no\nyes\n");
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and((string) file_get_contents(workflowPath()))->toBe(InitCommand::WORKFLOW_TEMPLATE)
            ->and(baselineFiles())->toBe([]);
    });

});

describe('empty baseline warning', function () {
    test('a scripted init --workflow that leaves the baseline empty warns loudly', function () {
        chdirWorkspace();
        createImage('photo.png');
        Http::fake();

        // Artisan::call cannot answer the seed confirm, so it falls through
        // to No: the exact shape of every scripted `init --workflow` run.
        expect(Artisan::call('init', ['--workflow' => true]))->toBe(0)
            ->and(Artisan::output())->toContain(INIT_EMPTY_BASELINE_WARNING);

        Http::assertNothingSent();
    });

    test('a kept existing workflow with an empty baseline also warns', function () {
        chdirWorkspace();
        writeWorkflow();
        Http::fake();

        expect(Artisan::call('init'))->toBe(0)
            ->and(Artisan::output())->toContain(INIT_EMPTY_BASELINE_WARNING);
    });

    test('no warning when the baseline is seeded in the same run', function () {
        chdirWorkspace();
        createImage('photo.png');
        putenv('GLIMPSE_TOKEN=test-token');
        Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

        expect(Artisan::call('init', ['--workflow' => true, '--update-baseline' => true]))->toBe(0)
            ->and(Artisan::output())->not->toContain('first CI run will re-check');
    });

    test('no warning when no workflow is involved', function () {
        chdirWorkspace();
        createImage('photo.png');
        Http::fake();

        expect(Artisan::call('init'))->toBe(0)
            ->and(Artisan::output())->not->toContain('first CI run will re-check');
    });

    test('a baseline kept from an earlier run that is still empty also warns', function () {
        chdirWorkspace();
        createImage('photo.png');
        Http::fake();

        // First scripted run scaffolds the empty baseline, the second adds
        // the workflow: the trap state is reached across two runs, so the
        // warning must key on the file content, not on what this run did.
        expect(Artisan::call('init'))->toBe(0)
            ->and(Artisan::call('init', ['--workflow' => true]))->toBe(0)
            ->and(Artisan::output())->toContain(INIT_EMPTY_BASELINE_WARNING);
    });

    test('no warning when a kept baseline is populated', function () {
        chdirWorkspace();
        $entry = baselineEntry(createImage('photo.png'));
        writeBaseline(['photo.png' => $entry]);
        Http::fake();

        expect(Artisan::call('init', ['--workflow' => true]))->toBe(0)
            ->and(Artisan::output())->not->toContain('first CI run will re-check');
    });

    test('a failed seed with a workflow still warns and keeps the failure exit code', function () {
        chdirWorkspace();
        createImage('photo.png');
        putenv('GLIMPSE_TOKEN=test-token');
        Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        expect(Artisan::call('init', ['--update-baseline' => true, '--workflow' => true]))->toBe(1)
            ->and(Artisan::output())->toContain(INIT_EMPTY_BASELINE_WARNING);
    });

    test('a failed refresh of a populated baseline does not claim it is empty', function () {
        chdirWorkspace();
        $entry = baselineEntry(createImage('photo.png'));
        writeBaseline(['photo.png' => $entry]);
        // A new image forces a real API call during the refresh; the
        // unchanged photo.png alone would be reused from its hash and the
        // scan would succeed without ever hitting the fake.
        createImage('new.png');
        putenv('GLIMPSE_TOKEN=test-token');
        Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        // The refresh fails but the populated baseline on disk is intact,
        // so CI behaves exactly as before the run: no warning.
        expect(Artisan::call('init', ['--update-baseline' => true, '--workflow' => true]))->toBe(1)
            ->and(Artisan::output())->not->toContain('The baseline is empty');
    });
});
