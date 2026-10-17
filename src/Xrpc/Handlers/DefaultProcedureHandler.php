<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Index\IndexesRecordsNow;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpClient\Exceptions\AtpResponseException;
use SocialDept\AtpSchema\Facades\Schema;

/**
 * Writes a record to the viewer's own repo through the session the server
 * holds for them, then indexes it straight away. Backend mode only: in
 * frontend mode the browser holds the tokens and writes to its PDS itself.
 *
 * Inputs and outputs follow com.atproto.repo:
 *
 * - create: {record, rkey?} answers {uri, cid}
 * - put:    {rkey, record, swapRecord?} answers {uri, cid}
 * - delete: {rkey, swapRecord?} answers nothing
 */
class DefaultProcedureHandler
{
    public const ACTIONS = ['create', 'put', 'delete'];

    public function __construct(
        protected IndexesRecordsNow $index,
    ) {
    }

    /**
     * @return array<string, string>|null
     */
    public function __invoke(XrpcRequest $request): ?array
    {
        $viewer = $request->viewer();
        $client = $viewer?->client() ?? throw new XrpcException(
            'BackendSessionRequired',
            'This server holds no session for this account. Write the record to your PDS directly.',
            403,
        );

        $collection = (string) $request->method->collection;
        $input = $request->input() ?? [];

        if ($this->index->blocked($viewer->did, $collection)) {
            throw new XrpcException('RepoBlocked', "Records from this repo in {$collection} are not accepted here.", 403);
        }

        try {
            return match ($request->method->action) {
                'create' => $this->create($client, $viewer->did, $collection, $input),
                'put' => $this->put($client, $viewer->did, $collection, $input),
                'delete' => $this->delete($client, $viewer->did, $collection, $input),
            };
        } catch (AtpResponseException $e) {
            // The PDS's own error, such as InvalidSwap, passed on as it came.
            throw new XrpcException($e->errorCode, $e->errorMessage, $e->httpStatus >= 400 && $e->httpStatus < 500 ? $e->httpStatus : 502);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    protected function create(AtpClient $client, string $did, string $collection, array $input): array
    {
        $record = $this->record($collection, $input);
        $rkey = $this->optionalString($input, 'rkey');

        // Validated above against the collection's own lexicon. A PDS asked to
        // validate refuses lexicons it does not know, which is most of them.
        $response = $client->atproto->repo->createRecord($collection, $record, $rkey, validate: false);
        $this->index->index($did, $collection, $this->rkeyOf($response->uri), RecordAction::Create, $response->cid, $this->rev($client, $did, $response->commit?->rev), $record);

        return ['uri' => $response->uri, 'cid' => $response->cid];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    protected function put(AtpClient $client, string $did, string $collection, array $input): array
    {
        $record = $this->record($collection, $input);
        $rkey = $this->requiredString($input, 'rkey');

        $response = $client->atproto->repo->putRecord($collection, $rkey, $record, false, $this->optionalString($input, 'swapRecord'));
        $this->index->index($did, $collection, $rkey, RecordAction::Update, $response->cid, $this->rev($client, $did, $response->commit?->rev), $record);

        return ['uri' => $response->uri, 'cid' => $response->cid];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function delete(AtpClient $client, string $did, string $collection, array $input): null
    {
        $rkey = $this->requiredString($input, 'rkey');

        $response = $client->atproto->repo->deleteRecord($collection, $rkey, $this->optionalString($input, 'swapRecord'));
        $this->index->index($did, $collection, $rkey, RecordAction::Delete, null, $this->rev($client, $did, $response->commit?->rev), null);

        return null;
    }

    /**
     * The record from the input, typed as the collection and valid against
     * its lexicon when one is loaded.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function record(string $collection, array $input): array
    {
        $record = $input['record'] ?? null;

        if (! is_array($record)) {
            throw XrpcException::invalidRequest('The input needs a record object.');
        }

        $record['$type'] ??= $collection;

        if ($record['$type'] !== $collection) {
            throw XrpcException::invalidRequest("The record's \$type must be {$collection}.");
        }

        if (Schema::exists($collection) && ($errors = Schema::validateWithErrors($collection, $record)) !== []) {
            throw XrpcException::invalidRequest('The record is not a valid '.$collection.': '.$this->describe($errors));
        }

        return $record;
    }

    /**
     * The commit's revision, which a PDS returns with every write, else the
     * repo's latest, which is at least as new.
     */
    protected function rev(AtpClient $client, string $did, ?string $rev): string
    {
        return $rev ?? $client->atproto->sync->getLatestCommit($did)->rev;
    }

    protected function rkeyOf(string $uri): string
    {
        return substr($uri, strrpos($uri, '/') + 1);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function requiredString(array $input, string $key): string
    {
        return $this->optionalString($input, $key) ?? throw XrpcException::invalidRequest("The input needs {$key}.");
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function optionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if ($value !== null && (! is_string($value) || $value === '')) {
            throw XrpcException::invalidRequest("{$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $errors
     */
    protected function describe(array $errors): string
    {
        $messages = [];

        array_walk_recursive($errors, function ($message) use (&$messages) {
            $messages[] = (string) $message;
        });

        return implode(' ', array_slice($messages, 0, 5));
    }
}
