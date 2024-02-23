<?php

namespace App\Command\Content;

use App\Contracts\AgentInterface;
use App\Entity\AgentConfig;
use App\Entity\Project;
use App\Entity\ProjectAgentConnection;
use App\Enum\AgentFieldType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\{Command\Command, Question\ChoiceQuestion, Question\Question, Style\SymfonyStyle};
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

abstract class AbstractContentManagementCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        #[TaggedIterator('agi.task.agent')]
        protected iterable $agents,
    ) {
        parent::__construct();
    }

    protected function createAgentConnection(SymfonyStyle $io, Project $project): void
    {
        $currentProjectAgents = $project->agents->map(static fn(ProjectAgentConnection $pac) => $pac->agent->name)->toArray();
        if (!empty($currentProjectAgents)) {
            $io->info('Project agents: ' . implode(', ', $currentProjectAgents));
        }

        $agents = $this->entityManager->getRepository(AgentConfig::class)->findAll();
        if (empty($agents)) {
            $io->error('No agents found');
            if ($io->confirm('Create new agent config?')) {
                $agents[] = $this->createAgentConfig($io);
            }
        }

        $agentChoise = [];
        /** @var AgentConfig $agentConfig */
        foreach ($agents as $agentConfig) {
            $agentChoise[] = $agentConfig->name . ' (' . $agentConfig->id . ')';
        }
        $agentConfigSelection = $io->askQuestion(
            new ChoiceQuestion('Please select agent:', $agentChoise)
        );
        foreach ($agents as $agentConfig) {
            if ($agentConfig->name . ' (' . $agentConfig->id . ')' === $agentConfigSelection) {
                $agent = $this->getAgentByType($agentConfig->type);
                if (!$agent) {
                    $io->error('Agent not found');

                    return;
                }

                $projectAgentConnection = new ProjectAgentConnection();
                $projectAgentConnection->project = $project;
                $projectAgentConnection->agent = $agentConfig;
                $projectAgentConnection->config = [];
                foreach ($agent->getConnectionFields() as $fieldName => $fieldDefinition) {
                    $projectAgentConnection->config[$fieldName] = $this->askFieldByDefinition(
                        $io, $fieldDefinition, $fieldName
                    );
                }
                $this->entityManager->persist($projectAgentConnection);
                $this->entityManager->flush();
                $io->success('Agent connection created successfully');
                break;
            }
        }
    }

    protected function createAgentConfig(SymfonyStyle $io): ?AgentConfig
    {
        $requiredFieldFunc = static function ($value) {
            if (empty($value)) {
                throw new \Exception('cannot be empty');
            }

            return $value;
        };

        $agentTypes = [];
        /** @var AgentInterface $agent */
        foreach ($this->agents as $agent) {
            $agentTypes[] = $agent->getType();
        }

        $agentConfig = new AgentConfig();
        $agentConfig->type = $io->choice('Please select agent type:', $agentTypes);
        $agentConfig->name = $io->ask('Agent name', validator: $requiredFieldFunc);
        $agentConfig->accessName = $io->ask('Access Name');
        $agentConfig->accessKey = $io->ask('Access Key');

        $selectedAgent = $this->getAgentByType($agentConfig->type);
        if (!$selectedAgent) {
            $io->error('Select agent type');
        }

        foreach ($selectedAgent->getExtraDataFields() as $fieldName => $fieldDefinition) {
            $agentConfig->extraData[$fieldName] = $this->askFieldByDefinition($io, $fieldDefinition, $fieldName);
        }

        $this->entityManager->persist($agentConfig);
        $this->entityManager->flush();

        $io->success('Agent config created successfully');

        return $agentConfig;
    }

    public function getAgentByType(string $type): ?AgentInterface
    {
        foreach ($this->agents as $agent) {
            if ($agent->getType() === $type) {
                return $agent;
            }
        }

        return null;
    }

    protected function askFieldByDefinition(SymfonyStyle $io, $fieldDefinition, int|string $fieldName): mixed
    {
        $fieldLabel = $fieldDefinition['label'] ?? $fieldName;

        $io->note(
            $fieldLabel
            . (isset($fieldDefinition['type']) ? ' [' . $fieldDefinition['type']->value . ']' : '')
            . (!empty($fieldDefinition['required']) ? ' required' : ' optional')
        );
        if (!empty($fieldDefinition['description'])) {
            $io->comment($fieldDefinition['description']);
        }

        if (isset($fieldDefinition['type']) && $fieldDefinition['type'] === AgentFieldType::BOOL) {
            return $io->confirm($fieldLabel);
        }

        $q = new Question($fieldLabel);
        if (!empty($fieldDefinition['required'])) {
            $q->setValidator(function ($value) use ($fieldLabel) {
                if (empty($value)) {
                    throw new \Exception($fieldLabel . ' cannot be empty');
                }

                return $value;
            });
        }
        if (isset($fieldDefinition['type']) && $fieldDefinition['type'] === AgentFieldType::TEXT) {
            $q->setMultiline(true);
        }

        $answer = $io->askQuestion($q);

        if (
            isset($fieldDefinition['type'])
            && $fieldDefinition['type'] === AgentFieldType::INT
            && is_numeric($answer)
        ) {
            return (int)$answer;
        }

        return $answer;
    }
}