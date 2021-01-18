<?php
namespace Kitzberger\CliBrowser\Command;

use Symfony\Component\Console\Command\Command;
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
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class RecordBrowserCommand extends Command
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
	}

	/**
	 * Executes the command
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if ($output->isVerbose()) {
			$this->io = new SymfonyStyle($input, $output);
			$this->io->title($this->getDescription());
		}

		$this->conf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cli_browser'];


        // ************************
        // 1. Determine parameters
        // ************************
        $table = $input->getOption('table');
        $type = $input->getOption('type');

        $helper = $this->getHelper('question');

        if (empty($table)) {
            $tables = $GLOBALS['TCA'];

            $question = new ChoiceQuestion(
                'Table? ',
                array_keys($tables),
                0
            );
            $table = $helper->ask($input, $output, $question);
        }

        if (isset($GLOBALS['TCA'][$table]['ctrl']['type'])) {
            // Ask for type?!
            $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];
            $type = 'xxx';
        }

        $labelField = $GLOBALS['TCA'][$table]['ctrl']['label'];
        $createField = $GLOBALS['TCA'][$table]['ctrl']['crdate'];
        $tstampField = $GLOBALS['TCA'][$table]['ctrl']['tstamp'];

        // ************************
        // 2. Count elements
        // ************************
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $constraints = [
            $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
        ];
        if ($typeField && $type) {
        #    $constraints[] = $queryBuilder->expr()->eq($typeField, $queryBuilder->createNamedParameter($type, \PDO::PARAM_STR));
        }
        $total = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(...$constraints)
            ->execute()->fetchColumn(0);

        if ($typeField && $type) {
            $output->writeln(PHP_EOL . sprintf('It\'s a total of %s available %s records of type %s', $total, $table, $type) . PHP_EOL);
        } else {
            $output->writeln(PHP_EOL . sprintf('It\'s a total of %s available %s records', $total, $table) . PHP_EOL);
        }

        // ************************
        // 3. List elements
        // ************************
        if ($table) {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

            do {
                $limit = 5;

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

                // todo: add parameter to toggle this here
                if (0) {
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                }

                $fields = [
                    'r.uid',
                    'r.pid',
                    'r.' . $labelField,
                    'r.' . $tstampField,
                ];

                $constraints = [
                    $queryBuilder->expr()->gt('r.pid', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                ];

                if ($typeField) {
                    $fields[] = $table . '.' . $typeField;
                    $constraints[] = $queryBuilder->expr()->eq('r.' . $typeField, $queryBuilder->createNamedParameter($type, \PDO::PARAM_STR));
                }

                $records = $queryBuilder
                    ->select(...$fields)
                    ->from($table, 'r')
                    ->join(
                        'r',
                        'pages',
                        'p',
                        $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.pid'))
                    )
                    ->where(...$constraints)
                    ->orderBy('r.' . $tstampField, 'DESC')
                    ->setMaxResults($limit)
                    ->execute()->fetchAll();

                $output->writeln(sprintf('Listing %d records of %s of type %s', count($records), $table, $type));

                // foreach ($records as &$plugin) {
                //     $site = $siteFinder->getSiteByPageId($plugin['pid']);
                //     $plugin['site'] = $site->getIdentifier();
                //     $plugin['url'] = $cObj->typolink_URL(array('parameter' => $plugin['pid']));
                // }

                $table = new Table($output);
                $table
                    ->setHeaders(array_keys($records[0]))
                    ->setRows(
                        array_map(
                            function($record) use ($tstampField) {
                                if ($record[$tstampField]) {
                                    $record[$tstampField] = date('Y-m-d H:i', $record[$tstampField]);
                                }
                                return $record;
                            },
                            $records)
                    );
                ;
                $table->render();

                $question = new ConfirmationQuestion(
                    'Continue with this action? (Y/n) ',
                    true,
                    '/^(y|j)/i'
                );

            } while (0 && $helper->ask($input, $output, $question));
        } else {
            // not implemente yet
        }

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

    private function initializeTypoScriptFrontend($pageId)
    {
        if (isset($GLOBALS['TSFE']) && is_object($GLOBALS['TFSE'])) {
            return;
        }

        $GLOBALS['TSFE'] = $this->objectManager->get(TypoScriptFrontendController::class, $GLOBALS['TYPO3_CONF_VARS'], $pageId, '');
        $GLOBALS['TSFE']->sys_page = $this->objectManager->get(PageRepository::class);
        $GLOBALS['TSFE']->sys_page->init(false);
        $GLOBALS['TSFE']->tmpl = $this->objectManager->get(TemplateService::class);
        $GLOBALS['TSFE']->tmpl->init();
        $GLOBALS['TSFE']->connectToDB();
        $GLOBALS['TSFE']->initFEuser();
        $GLOBALS['TSFE']->determineId();
        $GLOBALS['TSFE']->initTemplate();
        $GLOBALS['TSFE']->getConfigArray();
    }
}
