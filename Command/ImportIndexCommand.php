<?php

/*
 * This file is part of the Search PHP Bundle.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 * @author PuntMig Technologies
 */

declare(strict_types=1);

namespace Puntmig\Search\Command;

use Puntmig\Search\Model\Coordinate;
use Puntmig\Search\Model\Item;
use Puntmig\Search\Model\ItemUUID;
use Puntmig\Search\Query\Query;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Puntmig\Search\Repository\RepositoryBucket;

/**
 * ImportIndexCommand.
 */
class ImportIndexCommand extends Command
{
    /**
     * @var RepositoryBucket
     *
     * Repository bucket
     */
    private $repositoryBucket;

    /**
     * ResetIndexCommand constructor.
     *
     * @param RepositoryBucket $repositoryBucket
     */
    public function __construct(RepositoryBucket $repositoryBucket)
    {
        parent::__construct();

        $this->repositoryBucket = $repositoryBucket;
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this
            ->setName('puntmig:search:import-index')
            ->setDescription('Import your index')
            ->addArgument(
                'repository',
                InputArgument::REQUIRED,
                'Repository name'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'File'
            );
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @see setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repositoryName = $input->getArgument('repository');
        $file = $input->getArgument('file');
        $repository = $this
            ->repositoryBucket
            ->getRepositoryByName($repositoryName);

        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                $itemAsArray = [
                    'uuid' => [
                        'id' => $data[0],
                        'type' => $data[1],
                    ],
                    'metadata' => json_decode($this->jsonRemoveUnicodeSequences($data[2]), true),
                    'indexed_metadata' => json_decode($this->jsonRemoveUnicodeSequences($data[3]), true),
                    'searchable_metadata' => json_decode($this->jsonRemoveUnicodeSequences($data[4]), true),
                    'exact_matching_metadata' => json_decode($this->jsonRemoveUnicodeSequences($data[5]), true),
                    'suggest' => json_decode($this->jsonRemoveUnicodeSequences($data[6]), true),
                ];

                if (is_array($data[7])) {
                    $itemAsArray['coordinate'] = $data[7];
                }

                if (isset($itemAsArray['indexed_metadata']['rating'])) {
                    $itemAsArray['indexed_metadata']['rating'] = ceil($itemAsArray['indexed_metadata']['rating'] / 2);
                } else {
                    $itemAsArray['indexed_metadata']['rating'] = 0;
                }

                $item = Item::createFromArray($itemAsArray);
                $repository->addItem($item);
                $repository->flush(500, true);

            }
            $repository->flush(500, false);
            fclose($handle);
        }
    }

    function jsonRemoveUnicodeSequences($struct) {
       return utf8_decode(preg_replace_callback("/\\\\u([a-f0-9]{4})/", function($x) {
           return iconv('UCS-4LE','UTF-8',pack('V', hexdec('U' . $x[1])));
        }, $struct));
    }
}
