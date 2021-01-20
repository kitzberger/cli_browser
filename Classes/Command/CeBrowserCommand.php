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
use Symfony\Component\Console\Helper\QuestionHelper;
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

class CeBrowserCommand extends AbstractBrowserCommand
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
        $this->setDescription('CLI browser to find content elements!');

        $this->addOption(
            'CType',
            null,
            InputOption::VALUE_REQUIRED,
            'What CType are you looking for?',
            null
        );

        $this->addOption(
            'list_type',
            null,
            InputOption::VALUE_REQUIRED,
            'What list_type are you looking for?',
            null
        );

        $this->addOption(
            'url',
            null,
            InputOption::VALUE_NONE,
            'Render the URL?',
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

        $this->table = 'tt_content';

        parent::initSelectFields();

        // ************************
        // 1. Determine parameters
        // ************************
        $CType = $input->getOption('CType');
        $list_type = $input->getOption('list_type');
        $renderUrl = $input->getOption('url');
        $renderSite = $input->getOption('site');

        if (empty($CType)) {
            $CType = $this->askForType(null);
        }

        if ($CType === 'list' && empty($list_type)) {
            $list_type = $this->askForSubType('list');
        }

        // ************************
        // 2. Count elements
        // ************************
        $queryBuilder = $this->getQueryBuilder();

        $restrictions = $queryBuilder->getRestrictions()->removeAll();
        if ($this->isWithRestriction('deleted')) {
            $restrictions->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }
        if ($this->isWithRestriction('disabled')) {
            $restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        if ($this->isWithRestriction('starttime')) {
            $restrictions->add(GeneralUtility::makeInstance(StartTimeRestriction::class));
        }
        if ($this->isWithRestriction('endtime')) {
            $restrictions->add(GeneralUtility::makeInstance(EndTimeRestriction::class));
        }
        $queryBuilder->setRestrictions($restrictions);

        $constraints = [
            $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];
        if ($CType) {
            $constraints[] = $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR));
        }
        if ($list_type) {
            $constraints[] = $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter($list_type, \PDO::PARAM_STR));
        }
        $total = $queryBuilder
            ->count('uid')
            ->from($this->table)
            ->where(...$constraints)
            ->execute()->fetchColumn(0);

        if ($list_type) {
            $output->writeln(PHP_EOL . sprintf('It\'s a total of %s available items of list_type %s', $total, $list_type) . PHP_EOL);
        } else {
            $output->writeln(PHP_EOL . sprintf('It\'s a total of %s available items of CType %s', $total, $CType) . PHP_EOL);
        }

        // ************************
        // 3. List elements
        // ************************

        if (empty($this->selectFields)) {
            $this->selectFields = [
                'uid',
                'pid',
            ];
            if (is_null($CType)) {
                $this->selectFields[] = 'CType';
            }
            if ($CType === 'list' && empty($list_type)) {
                $this->selectFields[] = 'list_type';
            }
            $this->selectFields[] = 'header';
            if ($this->isWithRestriction('deleted') === false) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['delete'];
            }
            if ($this->isWithRestriction('disabled') === false) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
            }
            if ($this->isWithRestriction('starttime') === false) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime'];
            }
            if ($this->isWithRestriction('endtime') === false) {
                $this->selectFields[] = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime'];
            }
        }

        // Prefix each column with 'c.'
        $this->selectFields = preg_filter('/^/', 'c.', $this->selectFields);

        $selectFieldsLiteral = [];
        if ($CType === 'list') {
            $selectFieldsLiteral['switchableControllerActions'] = 'ExtractValue(`c`.`pi_flexform`, \'//T3FlexForms/data/sheet/language/field[@index="switchableControllerActions"]/value\') AS switchableControllerActions';
        }

        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->setRestrictions($restrictions);

        $constraints = [
            $queryBuilder->expr()->gt('c.pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];

        if ($CType) {
            $constraints[] = $queryBuilder->expr()->eq('c.CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR));
        }
        if ($CType === 'list' && $list_type) {
            $constraints[] = $queryBuilder->expr()->eq('c.list_type', $queryBuilder->createNamedParameter($list_type, \PDO::PARAM_STR));
        }

        $query = $queryBuilder
            ->select(...$this->selectFields)
            ->addSelectLiteral(join(',', $selectFieldsLiteral))
            ->from($this->table, 'c')
            ->join(
                'c',
                'pages',
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('c.pid'))
            )
            ->where(...$constraints)
            ->setMaxResults($this->limit);

        if ($CType === 'list' && $list_type) {
            $output->writeln(sprintf('Listing chunks of %d items of list_type %s', $this->limit, $list_type));
        } else {
            $output->writeln(sprintf('Listing chunks of %d items of CType %s', $this->limit, $CType));
        }
        $output->writeln(sprintf('- %scluding deleted', $this->isWithRestriction('deleted')   ? 'ex' : 'in'));
        $output->writeln(sprintf('- %scluding disabled', $this->isWithRestriction('disabled')  ? 'ex' : 'in'));
        $output->writeln(sprintf('- %scluding future', $this->isWithRestriction('starttime') ? 'ex' : 'in'));
        $output->writeln(sprintf('- %scluding past', $this->isWithRestriction('endtime')   ? 'ex' : 'in'));

        $output->writeln('');

        $offset = 0;

        do {
            $contents = $query->setFirstResult($offset)->execute()->fetchAll();

            if (count($contents)) {
                // Enhance results
                foreach ($contents as &$content) {
                    if ($renderSite) {
                        $content['site'] = $this->determineSiteIdentifier($content['pid']);
                    }
                    if ($renderUrl) {
                        $content['url'] = $this->cObj->typolink_URL(array('parameter' => $content['pid']));
                    }
                    if ($CType === 'list') {
                        $content['switchableControllerActions'] = str_replace('&gt;', '>', $content['switchableControllerActions']);
                    }
                    if (isset($content['starttime'])) {
                        $content['starttime'] = $content['starttime'] ? date('Y-m-d H:i', $content['starttime']) : '';
                    }
                    if (isset($content['endtime'])) {
                        $content['endtime'] = $content['endtime'] ? date('Y-m-d H:i', $content['endtime']) : '';
                    }
                }

                $tableOutput = new Table($output);
                $tableOutput
                    ->setHeaders(array_keys($contents[0]))
                    ->setRows($contents);
                ;
                $tableOutput->render();
            } else {
                $this->io->writeln('<warning>No records found ;-(</>');
            }

            $question = new ConfirmationQuestion(
                'Continue with this action? (Y/n) ',
                true,
                '/^(y|j)/i'
            );
        } while ($this->helper->ask($input, $output, $question) && $offset += $this->limit);

        // $sites = $siteFinder->getAllSites();
        // if (count($sites) > 1) {
        //     $output->writeln('There\'s more than one site. Please select one!');
        //     $table = new Table($output);
        //     $table
        //         ->setHeaders(['identifier', 'base', 'rootPid'])
        //         ->setRows(
        //             array_map(
        //                 function($site) {
        //                     return [
        //                         $site->getIdentifier(),
        //                         $site->getBase(),
        //                         $site->getRootPageId(),
        //                     ];
        //                 },
        //                 $sites
        //             )
        //         );
        //     ;
        //     $table->render();
        // }
    }
}
