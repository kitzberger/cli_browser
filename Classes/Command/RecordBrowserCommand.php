<?php
namespace Kitzberger\CliBrowser\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class RecordBrowserCommand extends AbstractBrowserCommand
{
    /**
     * @var SymfonyStyle
     */
    protected $io = null;

    /**
     * @var []
     */
    protected $conf = null;

    /**
     * Configure the command by defining the name
     */
    protected function configure()
    {
        $this->setDescription('CLI browser to find records!');

        $this->addOption(
            'table',
            null,
            InputOption::VALUE_REQUIRED,
            'What table are you looking for?',
            null
        );

        $this->addOption(
            'type',
            null,
            InputOption::VALUE_OPTIONAL,
            'What type are you looking for?',
            null
        );

        parent::configure();
    }

    /**
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        // ************************
        // 1. Determine parameters
        // ************************
        $this->table = $input->getOption('table');
        $type        = $input->getOption('type');

        if (empty($this->table)) {
            $tables = $GLOBALS['TCA'];

            $question = new ChoiceQuestion(
                'Table? ',
                array_keys($tables),
                0
            );
            $this->table = $this->ask($question);
        }

        if (isset($GLOBALS['TCA'][$this->table]['ctrl']['type'])) {
            // Ask for type?!
            $typeField = $GLOBALS['TCA'][$this->table]['ctrl']['type'];
            $type = null;
        }

        $labelField = $GLOBALS['TCA'][$this->table]['ctrl']['label'];
        $createField = $GLOBALS['TCA'][$this->table]['ctrl']['crdate'];
        $tstampField = $GLOBALS['TCA'][$this->table]['ctrl']['tstamp'];
        $enablecolumns = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns'];

        // ************************
        // 2. Count elements
        // ************************
        $queryBuilder = $this->getQueryBuilder();
        $restrictions = $queryBuilder->getRestrictions()->removeAll();
        if ($withoutDeleted === true) {
            $restrictions->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }
        if ($withoutHidden === true) {
            $restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        if ($withoutFuture === true) {
            $restrictions->add(GeneralUtility::makeInstance(StartTimeRestriction::class));
        }
        if ($withoutPast === true) {
            $restrictions->add(GeneralUtility::makeInstance(EndTimeRestriction::class));
        }

        $constraints = [
            $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];
        if ($typeField && $type) {
            #    $constraints[] = $queryBuilder->expr()->eq($typeField, $queryBuilder->createNamedParameter($type, \PDO::PARAM_STR));
        }
        $total = $queryBuilder
            ->count('uid')
            ->from($this->table)
            ->where(...$constraints)
            ->execute()->fetchColumn(0);

        $message = PHP_EOL;
        if ($typeField && $type) {
            $message = sprintf('It\'s a total of %s available %s records of type %s', $total, $this->table, $type) . PHP_EOL;
        } else {
            $message = sprintf('It\'s a total of %s available %s records', $total, $this->table) . PHP_EOL;
        }
        if ($withoutDeleted === false) {
            $message .= '- with deleted' . PHP_EOL;
        }
        if ($withoutHidden === false && isset($enablecolumns['disabled'])) {
            $message .= '- with hidden' . PHP_EOL;
        }
        if ($withoutFuture === false && isset($enablecolumns['starttime'])) {
            $message .= '- with future' . PHP_EOL;
        }
        if ($withoutPast === false && isset($enablecolumns['endtime'])) {
            $message .= '- with past' . PHP_EOL;
        }
        $output->writeln($message);

        // ************************
        // 3. List elements
        // ************************

        $selectFields = [
            'r.uid',
            'r.pid',
            'r.' . $labelField,
            'r.' . $tstampField,
        ];

        if ($this->isWithRestriction('deleted') === false) {
            $selectFields[] = 'r.' . $GLOBALS['TCA'][$this->table]['ctrl']['delete'];
        }
        if ($this->isWithRestriction('disabled') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled']) {
            $selectFields[] = 'r.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
        }
        if ($this->isWithRestriction('starttime') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime']) {
            $selectFields[] = 'r.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime'];
        }
        if ($this->isWithRestriction('endtime') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime']) {
            $selectFields[] = 'r.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime'];
        }

        do {
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder->setRestrictions($restrictions);

            $constraints = [
                $queryBuilder->expr()->gt('r.pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
            ];

            if ($typeField) {
                $selectFields[] = 'r.' . $typeField;
                if ($type) {
                    $constraints[] = $queryBuilder->expr()->eq('r.' . $typeField, $queryBuilder->createNamedParameter($type, \PDO::PARAM_STR));
                }
            }

            $records = $queryBuilder
                ->select(...$selectFields)
                ->from($this->table, 'r')
                ->join(
                    'r',
                    'pages',
                    'p',
                    $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.pid'))
                )
                ->where(...$constraints)
                ->orderBy('r.' . $tstampField, 'DESC')
                ->setMaxResults($this->limit)
                ->execute()->fetchAll();

            if ($typeField && $type) {
                $output->writeln(sprintf('Listing %d records of %s of type %s', count($records), $this->table, $type));
            } else {
                $output->writeln(sprintf('Listing %d records of %s', count($records), $this->table));
            }
            $output->writeln(sprintf('- %scluding deleted', $this->isWithRestriction('deleted')   ? 'ex' : 'in'));
            $output->writeln(sprintf('- %scluding disabled', $this->isWithRestriction('disabled')  ? 'ex' : 'in'));
            if ($this->isWithRestriction('starttime') !== null) {
                $output->writeln(sprintf('- %scluding future', $this->isWithRestriction('starttime') ? 'ex' : 'in'));
            }
            if ($this->isWithRestriction('endtime') !== null) {
                $output->writeln(sprintf('- %scluding past', $this->isWithRestriction('endtime')   ? 'ex' : 'in'));
            }
            $output->writeln('');

            if (count($records)) {
                // Enhance results
                foreach ($records as &$record) {
                    if ($record[$tstampField]) {
                        $record[$tstampField] = date('Y-m-d H:i', $record[$tstampField]);
                    }
                    if (isset($record['starttime'])) {
                        $record['starttime'] = $record['starttime'] ? date('Y-m-d H:i', $record['starttime']) : '';
                    }
                    if (isset($record['endtime'])) {
                        $record['endtime'] = $record['endtime'] ? date('Y-m-d H:i', $record['endtime']) : '';
                    }
                }

                $tableOutput = new Table($output);
                $tableOutput
                    ->setHeaders(array_keys($records[0]))
                    ->setRows($records);
                ;
                $tableOutput->render();
            } else {
                $this->io->writeln('<warning>No records found ;-(</>');
            }

            // $question = new ConfirmationQuestion(
            //     'Continue with this action? (Y/n) ',
            //     true,
            //     '/^(y|j)/i'
            // );
        } while (0 && $helper->ask($input, $output, $question));
    }
}
