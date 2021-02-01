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

        $this->addOption(
            'group-by-pid',
            null,
            InputOption::VALUE_NONE,
            'Group by pid?',
            null
        );

        $this->addOption(
            'group-by-site',
            null,
            InputOption::VALUE_NONE,
            'Group by site?',
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
        $this->type        = $input->getOption('type');

        $groupByPid  = $input->getOption('group-by-pid');
        $groupBySite = $input->getOption('group-by-site');
        $renderSite  = $input->getOption('site');

        parent::initSelectFields();
        parent::initRenderingInstructions();

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
            $this->typeField = $GLOBALS['TCA'][$this->table]['ctrl']['type'];
            if (is_null($this->type)) {
                $this->type = $this->askForType(null);
            }
        } else {
            $this->typeField = null;
        }

        $this->labelField = $GLOBALS['TCA'][$this->table]['ctrl']['label'];
        $this->createField = $GLOBALS['TCA'][$this->table]['ctrl']['crdate'];
        $this->tstampField = $GLOBALS['TCA'][$this->table]['ctrl']['tstamp'];
        $enablecolumns = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns'];

        // ************************
        // 2. Count elements
        // ************************
        $queryBuilder = $this->getQueryBuilder();
        $this->restrictions = $queryBuilder->getRestrictions()->removeAll();
        if ($withoutDeleted === true) {
            $this->restrictions->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }
        if ($withoutHidden === true) {
            $this->restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        if ($withoutFuture === true) {
            $this->restrictions->add(GeneralUtility::makeInstance(StartTimeRestriction::class));
        }
        if ($withoutPast === true) {
            $this->restrictions->add(GeneralUtility::makeInstance(EndTimeRestriction::class));
        }

        $constraints = [
            $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];
        if ($this->typeField && !is_null($this->type)) {
            $constraints[] = $queryBuilder->expr()->eq($this->typeField, $queryBuilder->createNamedParameter($this->type, \PDO::PARAM_STR));
        }
        $total = $queryBuilder
            ->count('uid')
            ->from($this->table)
            ->where(...$constraints)
            ->execute()->fetchColumn(0);

        $message = PHP_EOL;
        if ($this->typeField && !is_null($this->type)) {
            $message = sprintf('It\'s a total of %s available %s records of type %s', $total, $this->table, $this->type) . PHP_EOL;
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

        if ($groupByPid) {
            $query = $this->createGroupByPidQuery();
        } else {
            $query = $this->createQuery();
        }

        if ($this->typeField && $this->type) {
            $output->writeln(sprintf('Listing chunks of %d records from %s having type %s', $this->limit, $this->table, $this->type));
        } else {
            $output->writeln(sprintf('Listing chunks of %d records from %s', $this->limit, $this->table));
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

        $offset = 0;

        do {
            $records = $query->setFirstResult($offset)->execute()->fetchAll();

            if (count($records)) {
                // Enhance results
                foreach ($records as &$record) {
                    if ($renderSite) {
                        $record['site'] = $this->determineSiteIdentifier($record['pid']);
                    }
                    foreach ($this->renderingInstructions as $columnName => $renderingInstruction) {
                        $record[$columnName] = $this->renderColumn($record[$columnName], $renderingInstruction);
                    }
                }

                $tableOutput = new Table($output);
                $tableOutput
                    ->setHeaders(array_keys($records[0]))
                    ->setRows($records);
                ;
                $tableOutput->render();
            } else {
                $this->io->writeln('<comment>No records found ;-(</>');
            }

            $offset += $this->limit; // increase offset by chunk size (limit)
            if ($offset >= $total) {
                $continue = false;
            } else {
                $question = new ConfirmationQuestion(
                    sprintf('Print rows %d-%d of a total of %d rows? (Y/n) ', $offset+1, min($offset+$this->limit, $total), $total),
                    true,
                    '/^(y|j)/i'
                );
                $continue = $this->helper->ask($input, $output, $question);
            }
        } while ($continue);
    }

    protected function createQuery()
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->setRestrictions($this->restrictions);

        $constraints = [
            $queryBuilder->expr()->gt('r.pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];

        if ($this->typeField && $this->type) {
            $constraints[] = $queryBuilder->expr()->eq('r.' . $this->typeField, $queryBuilder->createNamedParameter($this->type, \PDO::PARAM_STR));
        }

        if (empty($this->selectFields)) {
            $this->selectFields = [
                'uid',
                'pid',
            ];
            if ($this->typeField && is_null($this->type)) {
                $this->selectFields[] = $this->typeField;
            }
            $this->selectFields[] = $this->labelField;
            $this->selectFields[] = $this->tstampField;
            if ($this->isWithRestriction('deleted') === false) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['delete'];
            }
            if ($this->isWithRestriction('disabled') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled']) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
            }
            if ($this->isWithRestriction('starttime') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime']) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime'];
            }
            if ($this->isWithRestriction('endtime') === false && $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime']) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime'];
            }
        }

        // Prefix each column with 'r.'
        $this->selectFields = preg_filter('/^/', 'r.', $this->selectFields);

        $query = $queryBuilder
            ->select(...$this->selectFields)
            ->from($this->table, 'r')
            ->join(
                'r',
                'pages',
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.pid'))
            )
            ->where(...$constraints);

        if ($this->orderBy) {
            foreach ($this->orderBy as $column) {
                $query->addOrderBy('r.' . $column[0], $column[1]);
            }

        }

        if ($this->limit) {
            $query->setMaxResults($this->limit);
        }

        return $query;
    }

    protected function createGroupByPidQuery()
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->setRestrictions($this->restrictions);

        $constraints = [
            $queryBuilder->expr()->gt('r.pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];

        if ($this->typeField && $this->type) {
            $constraints[] = $queryBuilder->expr()->eq('r.' . $this->typeField, $queryBuilder->createNamedParameter($this->type, \PDO::PARAM_STR));
        }

        $query = $queryBuilder
            ->selectLiteral('COUNT(*) AS count, r.pid')
            ->from($this->table, 'r')
            ->join(
                'r',
                'pages',
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.pid'))
            )
            ->where(...$constraints);

        if ($this->orderBy) {
            foreach ($this->orderBy as $column) {
                if ($column[0] === 'count') {
                    $query->addOrderBy($column[0], $column[1]);
                } else {
                    $query->addOrderBy('r.' . $column[0], $column[1]);
                }
            }

        }

        if ($this->limit) {
            $query->setMaxResults($this->limit);
        }

        $query->groupBy('r.pid');

        return $query;
    }
}
