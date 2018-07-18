<?php
declare (strict_types=1);

namespace Project\Controller;

use Project\Module\Competition\CompetitionService;
use Project\Module\GenericValueObject\Date;
use Project\Module\Reader\ReaderService;
use Project\Module\Runner\Runner;
use Project\Module\Runner\RunnerService;
use Project\Utilities\Tools;

/**
 * Class AdminController
 * @package Project\Controller
 */
class AdminController extends DefaultController
{
    /**
     * @throws \InvalidArgumentException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function adminAction(): void
    {
        $competitionService = new CompetitionService($this->database);

        $allCompetitionTypes = $competitionService->getAllCompetitionTypes();

        $this->viewRenderer->addViewConfig('allCompetitionTypes', $allCompetitionTypes);
        $this->viewRenderer->addViewConfig('page', 'admin');

        $this->viewRenderer->renderTemplate();
    }

    public function importCsvAction(): void
    {
        $runnerService = new RunnerService($this->database, $this->configuration);
        $readerService = new ReaderService();

        $runner = $readerService->readRunnerFile($runnerService);

        foreach ($runner as $singleRunner) {
            $runnerService->saveRunner($singleRunner);
        }
    }

    public function createCompetitionAction(): void
    {
        $competitionService = new CompetitionService($this->database);

        $competitions = $competitionService->getCompetitionsByParameter($_POST);

        if (empty($competitions) === true) {
            $this->notificationService->setError('Die Wettbewerbe konnten nicht erstellt werden. Nötige Daten fehlen.');
            header('Location: ' . Tools::getRouteUrl('admin'));
            exit;
        }

        foreach ($competitions as $competition) {
            if ($competitionService->saveCompetition($competition) === false) {
                $this->notificationService->setError('Die Wettbewerbe konnten nicht komplett gespeichert werden.');
                header('Location: ' . Tools::getRouteUrl('admin'));
                exit;
            }
        }

        $this->notificationService->setSuccess('Die Wettbewerbe wurden erfolgreich erstellt.');
        header('Location: ' . Tools::getRouteUrl('admin'));
        exit;
    }

    /**
     * 1. Import runner data and create an array of Runner
     * 2. save all runner in repository
     * 3. save runner startnumber and other data in competition_runner to register them for the run
     */
    public function uploadRunnerFileAction(): void
    {
        $runnerService = new RunnerService($this->database, $this->configuration);
        $readerService = new ReaderService();

        $competitionDate = null;

        if (Tools::getValue('date') !== false) {
            $competitionDate = Date::fromValue(Tools::getValue('date'));
        }

        $runner = $readerService->readRunnerFile($runnerService, $_FILES['runnerFile']['tmp_name'], $competitionDate);

        if (\count($runner) === 0) {
            $this->notificationService->setError('Die Teilnehmer konnten nicht importiert werden. Entweder ist es die falsche Kodierung, oder die Formatierung stimmt nicht überein, oder die Datei ist leer.');
            header('Location: ' . Tools::getRouteUrl('admin'));
            exit;
        }

        foreach ($runner as $singleRunner) {
            if ($runnerService->saveRunner($singleRunner) === true) {
                continue;
            }
        }

        $this->notificationService->setSuccess('Die Teilnehmer konnten erfolgreich importiert werden.');
        header('Location: ' . Tools::getRouteUrl('admin'));
        exit;

    }

    public function findDuplicateNamesAction(): void
    {
        $duplicates = [];
        $runnerService = new RunnerService($this->database, $this->configuration);

        $allRunner = $runnerService->getAllRunner();
        $toCheckRunner = $allRunner;
        /** @var Runner $runner */
        foreach ($allRunner as $runner) {
            $testedRunner['runner'] = $runner;
            $testedRunner['duplicates'] = [];
            /** @var Runner $otherRunner */
            foreach ($toCheckRunner as $otherRunner) {
                if ($runner === $otherRunner) {
                    continue;
                }
                if ($runner->getSurname()->getName() === $otherRunner->getSurname()->getName() && $runner->getFirstname()->getName() === $otherRunner->getFirstname()->getName()) {
                    $testedRunner['duplicates'][] = $otherRunner;
                    continue;
                }
                if ($runner->getFirstname()->getName() === $otherRunner->getFirstname()->getName() && $runner->getAgeGroup()->getBirthYear()->getBirthYear() === $otherRunner->getAgeGroup()->getBirthYear()->getBirthYear()) {
                    $testedRunner['duplicates'][] = $otherRunner;
                    continue;
                }
                if ($runner->getSurname()->getName() === $otherRunner->getSurname()->getName() && $runner->getAgeGroup()->getBirthYear()->getBirthYear() === $otherRunner->getAgeGroup()->getBirthYear()->getBirthYear()) {
                    $testedRunner['duplicates'][] = $otherRunner;
                    continue;
                }
            }
            if (empty($testedRunner['duplicates']) === false) {
                $duplicates[] = $testedRunner;
            }
        }
        $this->viewRenderer->addViewConfig('duplicates', $duplicates);
        $this->viewRenderer->addViewConfig('page', 'duplicates');
        $this->viewRenderer->renderTemplate();
    }

    public function findDuplicatesByLevenshteinAction(): void
    {
        $duplicates = [];
        $runnerService = new RunnerService($this->database, $this->configuration);

        $allRunner = $runnerService->getAllRunner();
        $toCheckRunner = $allRunner;
        $duplicatedKeys = [];

        /** @var Runner $runner */
        foreach ($allRunner as $key => $runner) {
            if (\in_array($key, $duplicatedKeys, true)) {
                continue;
            }

            $testedRunner['runner'] = $runner;
            $testedRunner['duplicates'] = [];

            /** @var Runner $otherRunner */
            foreach ($toCheckRunner as $checkKey => $otherRunner) {
                if ($runner === $otherRunner) {
                    continue;
                }

                if ($runner->getAgeGroup()->getGender()->getGender() !== $otherRunner->getAgeGroup()->getGender()->getGender()) {
                    continue;
                }

                if (abs($runner->getAgeGroup()->getBirthYear()->getBirthYear() - $otherRunner->getAgeGroup()->getBirthYear()->getBirthYear()) > 2) {
                    continue;
                }

                $surnameDiff = levenshtein($runner->getSurname()->getName(), $otherRunner->getSurname()->getName());
                if ($surnameDiff === -1 || $surnameDiff > 2) {
                    continue;
                }

                $firstnameDiff = levenshtein($runner->getFirstname()->getName(), $otherRunner->getFirstname()->getName());
                if ($firstnameDiff === -1 || $firstnameDiff > 2) {
                    continue;
                }

                if (($surnameDiff + $firstnameDiff) < 3) {
                    $duplicatedKeys[] = $checkKey;
                    $testedRunner['duplicates'][] = $otherRunner;
                }
            }

            if (empty($testedRunner['duplicates']) === false) {
                $duplicates[] = $testedRunner;
            }
        }

        $this->viewRenderer->addViewConfig('duplicates', $duplicates);
        $this->viewRenderer->addViewConfig('page', 'duplicates');
        $this->viewRenderer->renderTemplate();
    }
}