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

class CeBrowserCommand extends Command
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
        $CType = $input->getOption('CType');
        $list_type = $input->getOption('list_type');

        $helper = $this->getHelper('question');

        if (empty($CType)) {
            $question = new Question('CType? (list) ', 'list');
            $CType = $helper->ask($input, $output, $question);
        }

        if ($CType === 'list' && empty($list_type)) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $list_types = $queryBuilder
                ->select('list_type')
                ->addSelectLiteral('COUNT(list_type) AS count')
                ->from('tt_content')
                ->where($queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR)))
                ->orderBy('list_type')
                ->groupBy('list_type')
                ->execute()->fetchAll();

            $question = new ChoiceQuestion(
                'list_type?',
                array_column($list_types, 'list_type'),
                0
            );
            $list_type = $helper->ask($input, $output, $question);
        }

        // ************************
        // 2. Count elements
        // ************************
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $constraints = [
            $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR))
        ];
        if ($list_type) {
            $constraints[] = $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter($list_type, \PDO::PARAM_STR));
        }
        $total = $queryBuilder
            ->count('uid')
            ->from('tt_content')
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
        if ($list_type) {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

            do {
                $limit = 5;

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');

                // todo: add parameter to toggle this here
                if (0) {
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                }

                $plugins = $queryBuilder
                    ->select('c.uid', 'c.pid', 'c.list_type')
                    ->addSelectLiteral('ExtractValue(`c`.`pi_flexform`, \'//T3FlexForms/data/sheet/language/field[@index="switchableControllerActions"]/value\') AS switchableControllerActions')
                    ->from('tt_content', 'c')
                    ->join(
                        'c',
                        'pages',
                        'p',
                        $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('c.pid'))
                    )
                    ->where(
                        $queryBuilder->expr()->eq('c.CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR)),
                        $queryBuilder->expr()->eq('c.list_type', $queryBuilder->createNamedParameter($list_type, \PDO::PARAM_STR))
                    )
                    ->setMaxResults($limit)
                    ->execute()->fetchAll();

                $output->writeln(sprintf('Listing %d items of list_type %s', count($plugins), $list_type));

                foreach ($plugins as &$plugin) {
                    $site = $siteFinder->getSiteByPageId($plugin['pid']);
                    $plugin['site'] = $site->getIdentifier();
                    $plugin['url'] = $cObj->typolink_URL(array('parameter' => $plugin['pid']));
                }

                $table = new Table($output);
                $table
                    ->setHeaders(array_keys($plugins[0]))
                    ->setRows($plugins);
                ;
                $table->render();

                // $question = new ConfirmationQuestion(
                //     'Continue with this action? (Y/n) ',
                //     true,
                //     '/^(y|j)/i'
                // );

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
