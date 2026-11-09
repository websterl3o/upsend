<?php

namespace App\Jobs;

use App\Models\CollectionList;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessCollectionList implements ShouldQueue
{
    use Queueable;

    public $tries = 5;
    protected CollectionList $collectionList;
    private const CHUNK_SIZE = 500;
    private const MIN_COLUMNS = 6;

    /**
     * Create a new job instance.
     */
    public function __construct(CollectionList $collectionList)
    {
        $this->collectionList = $collectionList;
    }

    public function collectionList(): CollectionList
    {
        return $this->collectionList;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Start - Processing collection list {$this->collectionList->id}");

        $file = $this->openFile();
        $chunk = [];

        while (($row = $file->fgetcsv()) !== false) {
            if (count($row) < self::MIN_COLUMNS) {
                continue;
            }

            $chunk[] = $row;

            if (count($chunk) === self::CHUNK_SIZE) {
                $this->processRegisterAndSendBilling($chunk);
                $chunk = [];
            }
        }

        $this->processRegisterAndSendBilling($chunk);

        $this->collectionList->update(['processed_at' => now()]);

        Log::info("End - Processing collection list {$this->collectionList->id}");
    }

    public function processRegisterAndSendBilling(array $chunk): void
    {
        if (count($chunk) === 0) {
            return;
        }

        RegisterAndSendBilling::dispatch($this->collectionList, $chunk);
    }

    public function openFile(): \SplFileObject
    {
        if (!Storage::exists($this->collectionList->path)) {
            Log::error('File not found. Collection list ID: ' . $this->collectionList->id);
            throw new \Exception('File not found. Collection list ID: ' . $this->collectionList->id);
        }

        $file = new \SplFileObject(storage_path('app/private/' . $this->collectionList->path));
        $file->setFlags(\SplFileObject::READ_CSV);

        return $file;
    }
}
