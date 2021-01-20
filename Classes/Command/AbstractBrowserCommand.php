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

abstract class AbstractBrowserCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    protected $io = null;

    /**
     * @var InputInterface
     */
    protected $input = null;

    /**
     * @var OutputInterface
     */
    protected $output = null;

    /**
     * @var QuestionHelper
     */
    protected $helper = null;

    /**
     * @var SiteFinder
     */
    protected $siteFinder = null;

    /**
     * @var ContentObjectRenderer
     */
    protected $cObj = null;

    /**
     * @var []
     */
    protected $conf = null;

    /**
     * @var string
     */
    protected $table = null;

    /**
     * @var []
     */
    protected $restrictions = null;

    /**
     * @var int
     */
    protected $limit = null;

    /**
     * Configure the command by defining the name
     */
    protected function configure()
    {
        $this->addOption(
            'with-deleted',
            null,
            InputOption::VALUE_NONE,
            'Include deleted records?'
        );

        $this->addOption(
            'without-hidden',
            null,
            InputOption::VALUE_NONE,
            'Include hidden records?'
        );

        $this->addOption(
            'without-past',
            null,
            InputOption::VALUE_NONE,
            'Include past records?'
        );

        $this->addOption(
            'without-future',
            null,
            InputOption::VALUE_NONE,
            'Include future records?'
        );

        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'How many records?',
            5
        );

        $this->addOption(
            'columns',
            null,
            InputOption::VALUE_OPTIONAL,
            'Which columns should be display?',
            false
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
        $this->io = new SymfonyStyle($input, $output);
        if ($output->isVerbose()) {
            $this->io->title($this->getDescription());
        }

        $this->input = $input;
        $this->output = $output;

        $this->conf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cli_browser'];
        $this->helper = $this->getHelper('question');

        $this->siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $this->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        $this->selectFields = $input->getOption('columns');

        $this->restrictionFields['deleted']  = !$input->getOption('with-deleted');
        $this->restrictionFields['disabled']  = $input->getOption('without-hidden');
        $this->restrictionFields['starttime'] = $input->getOption('without-future');
        $this->restrictionFields['endtime']   = $input->getOption('without-past');

        $this->limit = (int)$input->getOption('limit');
    }

    protected function initSelectFields()
    {
        if (is_null($this->selectFields)) {
            $columns = $GLOBALS['TCA'][$this->table]['columns'];
            $question = new ChoiceQuestion(
                'Columns? ',
                array_keys($columns),
                0
            );
            $question->setMultiselect(true);
            $this->selectFields = $this->ask($question);
        } elseif ($this->selectFields !== false) {
            $this->selectFields = GeneralUtility::trimExplode(',', $this->selectFields, true);
        }
    }

    protected function ask($question)
    {
        return $this->helper->ask($this->input, $this->output, $question);
    }

    protected function getTypeFieldName()
    {
        if (!isset($GLOBALS['TCA'][$this->table]['ctrl']['type'])) {
            throw new \Exception('Type field not defined for table ' . $this->table . '!');
        }

        return $GLOBALS['TCA'][$this->table]['ctrl']['type'];
    }


    protected function getSubTypeFieldName($type = 'list')
    {
        if (!isset($GLOBALS['TCA'][$this->table]['types'][$type]['subtype_value_field'])) {
            throw new \Exception('Sub type field not defined for ' . $typeField . ' ' . $type . '!');
        }

        return $GLOBALS['TCA'][$this->table]['types'][$type]['subtype_value_field'];
    }

    protected function askForType($default = 'list')
    {
        $typeFieldName = $this->getTypeFieldName();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $types = $queryBuilder
            ->select($typeFieldName)
            ->addSelectLiteral('COUNT(' . $typeFieldName . ') AS count')
            ->from($this->table)
            ->orderBy($typeFieldName)
            ->groupBy($typeFieldName)
            ->execute()->fetchAll();

        $types = array_column($types, $typeFieldName);
        $types[] = '[all]';

        $question = new ChoiceQuestion(
            $typeFieldName . '? (' . ($default ?? 'all'). ')',
            $types,
            $default ?? '[all]'
        );

        $answer = $this->helper->ask($this->input, $this->output, $question);
        if ($answer === '[all]') {
            return null;
        } else {
            return $answer;
        }
    }

    protected function askForSubType($type = 'list')
    {
        $typeFieldName = $this->getTypeFieldName();
        $subTypeFieldName = $this->getSubTypeFieldName($type);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $subtypes = $queryBuilder
            ->select($subTypeFieldName)
            ->addSelectLiteral('COUNT(' . $subTypeFieldName . ') AS count')
            ->from($this->table)
            ->where($queryBuilder->expr()->eq($typeFieldName, $queryBuilder->createNamedParameter($type, \PDO::PARAM_STR)))
            ->orderBy($subTypeFieldName)
            ->groupBy($subTypeFieldName)
            ->execute()->fetchAll();

        $question = new ChoiceQuestion(
            $subTypeFieldName . '?',
            array_column($subtypes, $subTypeFieldName),
            0
        );

        return $this->helper->ask($this->input, $this->output, $question);
    }

    protected function getQueryBuilder()
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
    }

    /**
     * @param string $restriction
     * @return boolean|null
     */
    protected function isWithRestriction($restriction)
    {
        if ($this->restrictionFields[$restriction]) {
            // restricted to given restriction
            if ($restriction === 'deleted' && isset($GLOBALS['TCA'][$this->table]['ctrl']['delete'])) {
                return true;
            } elseif (isset($GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns'][$restriction])) {
                return true;
            }
        } else {
            // not restricted to given restriction
            if ($restriction === 'deleted' && isset($GLOBALS['TCA'][$this->table]['ctrl']['delete'])) {
                return false;
            } elseif (isset($GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns'][$restriction])) {
                return false;
            }
        }

        // restriction not defined in TCA
        return null;
    }

    protected function initializeTypoScriptFrontend($pageId)
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
