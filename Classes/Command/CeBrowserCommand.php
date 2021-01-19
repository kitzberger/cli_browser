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

        // ************************
        // 1. Determine parameters
        // ************************
        $CType = $input->getOption('CType');
        $list_type = $input->getOption('list_type');

        if (empty($CType)) {
            $CType = $this->askForType('list');
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
            $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($CType, \PDO::PARAM_STR))
        ];
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

        $selectFields = [
            'c.uid',
            'c.pid',
            'c.list_type',
        ];

        if ($this->isWithRestriction('deleted') === false) {
            $selectFields[] = 'c.' . $GLOBALS['TCA'][$this->table]['ctrl']['delete'];
        }
        if ($this->isWithRestriction('disabled') === false) {
            $selectFields[] = 'c.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
        }
        if ($this->isWithRestriction('starttime') === false) {
            $selectFields[] = 'c.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['starttime'];
        }
        if ($this->isWithRestriction('endtime') === false) {
            $selectFields[] = 'c.' . $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['endtime'];
        }

        if ($list_type) {
            do {
                $queryBuilder = $this->getQueryBuilder();
                $queryBuilder->setRestrictions($restrictions);

                $plugins = $queryBuilder
                    ->select(...$selectFields)
                    ->addSelectLiteral('ExtractValue(`c`.`pi_flexform`, \'//T3FlexForms/data/sheet/language/field[@index="switchableControllerActions"]/value\') AS switchableControllerActions')
                    ->from($this->table, 'c')
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
                    ->setMaxResults($this->limit)
                    ->execute()->fetchAll();

                $output->writeln(sprintf('Listing %d items of list_type %s', count($plugins), $list_type));
                $output->writeln(sprintf('- %scluding deleted',  $this->isWithRestriction('deleted')   ? 'ex' : 'in'));
                $output->writeln(sprintf('- %scluding disabled', $this->isWithRestriction('disabled')  ? 'ex' : 'in'));
                $output->writeln(sprintf('- %scluding future',   $this->isWithRestriction('starttime') ? 'ex' : 'in'));
                $output->writeln(sprintf('- %scluding past',     $this->isWithRestriction('endtime')   ? 'ex' : 'in'));

                $output->writeln('');

                if (count($plugins)) {
                    // Enhance results
                    foreach ($plugins as &$plugin) {
                        $site = $this->siteFinder->getSiteByPageId($plugin['pid']);
                        $plugin['site'] = $site->getIdentifier();
                        $plugin['url'] = $this->cObj->typolink_URL(array('parameter' => $plugin['pid']));
                        $plugin['switchableControllerActions'] = str_replace('&gt;', '>', $plugin['switchableControllerActions']);
                        if (isset($plugin['starttime'])) {
                            $plugin['starttime'] = $plugin['starttime'] ? date('Y-m-d H:i', $plugin['starttime']) : '';
                        }
                        if (isset($plugin['endtime'])) {
                            $plugin['endtime'] = $plugin['endtime'] ? date('Y-m-d H:i', $plugin['endtime']) : '';
                        }
                    }

                    $tableOutput = new Table($output);
                    $tableOutput
                        ->setHeaders(array_keys($plugins[0]))
                        ->setRows($plugins);
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

            } while (0 && $this->helper->ask($input, $output, $question));
        } else {
            // not implemented yet
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
}
