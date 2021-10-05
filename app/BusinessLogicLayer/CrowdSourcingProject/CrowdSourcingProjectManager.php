<?php

namespace App\BusinessLogicLayer\CrowdSourcingProject;

use App\BusinessLogicLayer\CurrentQuestionnaireProvider;
use App\BusinessLogicLayer\gamification\ContributorBadge;
use App\BusinessLogicLayer\lkp\CrowdSourcingProjectStatusLkp;
use App\BusinessLogicLayer\questionnaire\QuestionnaireGoalManager;
use App\Models\CrowdSourcingProject\CrowdSourcingProject;
use App\Models\ViewModels\AllCrowdSourcingProjects;
use App\Models\ViewModels\CreateEditCrowdSourcingProject;
use App\Models\ViewModels\CrowdSourcingProjectForLandingPage;
use App\Models\ViewModels\CrowdSourcingProjectSocialMediaMetadata;
use App\Models\ViewModels\CrowdSourcingProjectUnavailable;
use App\Models\ViewModels\GamificationBadgeVM;
use App\Notifications\QuestionnaireResponded;
use App\Repository\CrowdSourcingProjectRepository;
use App\Repository\CrowdSourcingProjectStatusHistoryRepository;
use App\Repository\LanguageRepository;
use App\Repository\Questionnaire\QuestionnaireRepository;
use App\Utils\FileUploader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrowdSourcingProjectManager {
    protected $crowdSourcingProjectRepository;
    protected $questionnaireRepository;
    protected $crowdSourcingProjectStatusManager;
    protected $crowdSourcingProjectStatusHistoryRepository;
    protected $crowdSourcingProjectAccessManager;
    protected $questionnaireGoalManager;
    protected $currentQuestionnaireProvider;
    protected $crowdSourcingProjectCommunicationResourcesManager;
    protected $languageRepository;
    protected $crowdSourcingProjectColorsManager;

    public function __construct(CrowdSourcingProjectRepository                    $crowdSourcingProjectRepository,
                                QuestionnaireRepository                           $questionnaireRepository,
                                CrowdSourcingProjectStatusManager                 $crowdSourcingProjectStatusManager,
                                CrowdSourcingProjectAccessManager                 $crowdSourcingProjectAccessManager,
                                CrowdSourcingProjectStatusHistoryRepository       $crowdSourcingProjectStatusHistoryRepository,
                                QuestionnaireGoalManager                          $questionnaireGoalManager,
                                CurrentQuestionnaireProvider                      $currentQuestionnaireProvider,
                                CrowdSourcingProjectCommunicationResourcesManager $crowdSourcingProjectCommunicationResourcesManager,
                                LanguageRepository                                $languageRepository,
                                CrowdSourcingProjectColorsManager                 $crowdSourcingProjectColorsManager) {
        $this->crowdSourcingProjectRepository = $crowdSourcingProjectRepository;
        $this->questionnaireRepository = $questionnaireRepository;
        $this->crowdSourcingProjectStatusManager = $crowdSourcingProjectStatusManager;
        $this->crowdSourcingProjectStatusHistoryRepository = $crowdSourcingProjectStatusHistoryRepository;
        $this->crowdSourcingProjectAccessManager = $crowdSourcingProjectAccessManager;
        $this->questionnaireGoalManager = $questionnaireGoalManager;
        $this->currentQuestionnaireProvider = $currentQuestionnaireProvider;
        $this->crowdSourcingProjectCommunicationResourcesManager = $crowdSourcingProjectCommunicationResourcesManager;
        $this->languageRepository = $languageRepository;
        $this->crowdSourcingProjectColorsManager = $crowdSourcingProjectColorsManager;
    }

    public function getCrowdSourcingProjectsForHomePage(): Collection {
        return $this->crowdSourcingProjectRepository->getActiveProjectsWithAtLeastOneActiveQuestionnaire();
    }

    public function getPastCrowdSourcingProjectsForHomePage(): Collection {
        return $this->crowdSourcingProjectRepository->getPastProjects();
    }

    public function getCrowdSourcingProject(int $id): CrowdSourcingProject {
        return $this->crowdSourcingProjectRepository->find($id);
    }

    public function getCrowdSourcingProjectBySlug($project_slug) {
        return $this->crowdSourcingProjectRepository->findBy('slug', $project_slug);
    }

    public function getCrowdSourcingProjectViewModelForLandingPage($questionnaireId, $project_slug, $openQuestionnaireWhenPageLoads):
    CrowdSourcingProjectForLandingPage {
        $userId = null;
        // if the user is logged in, get the user id
        if (Auth::check())
            $userId = Auth::id();
        // else, check if the user is anonymous (by checking the cookie) and get the user id
        else if (isset($_COOKIE['crowdsourcing_anonymous_user_id']))
            $userId = intval($_COOKIE['crowdsourcing_anonymous_user_id']);

        $project = $this->getCrowdSourcingProjectBySlug($project_slug);

        $userResponse = null;
        $questionnaireGoalVM = null;
        $allResponses = collect([]);
        if ($questionnaireId)
            $questionnaire = $this->questionnaireRepository->find($questionnaireId);
        else
            $questionnaire = $this->currentQuestionnaireProvider->getCurrentQuestionnaire($project->id, $userId);

        if ($questionnaire) {
            $questionnaireGoalVM = $this->questionnaireGoalManager->getQuestionnaireGoalViewModel($questionnaire);
            $userResponse = $this->questionnaireRepository->getUserResponseForQuestionnaire($questionnaire->id, $userId);
            $allResponses = $this->questionnaireRepository->getAllResponsesForQuestionnaire($questionnaire->id);
            if ($userResponse != null)
                $openQuestionnaireWhenPageLoads = false;
        }

        $socialMediaMetadataVM = $this->getSocialMediaMetadataViewModel($project);
        return new CrowdSourcingProjectForLandingPage($project, $questionnaire,
            $userResponse,
            $allResponses,
            $questionnaireGoalVM,
            $socialMediaMetadataVM,
            $this->languageRepository->all(),
            $openQuestionnaireWhenPageLoads);
    }


    public function getSocialMediaMetadataViewModel(CrowdSourcingProject $project): CrowdSourcingProjectSocialMediaMetadata {
        return new CrowdSourcingProjectSocialMediaMetadata(
            $project->sm_title,
            $project->sm_description,
            $project->sm_featured_img_path,
            $project->sm_keywords,
            $project->slug
        );
    }

    public function createProject(array $attributes) {
        $attributes['user_creator_id'] = Auth::id();

        $attributes = $this->setDefaultValuesForCommonProjectFields($attributes);

        $project = $this->crowdSourcingProjectRepository->create($attributes);

        $this->updateCrowdSourcingProject($project->id, $attributes);
    }

    public function updateCrowdSourcingProject($id, array $attributes) {
        $project = $this->getCrowdSourcingProject($id);
        $attributes = $this->setDefaultValuesForCommonProjectFields($attributes, $project);

        $attributes = $this->setDefaultValuesForSocialMediaFields($project, $attributes);

        $attributes = $this->storeProjectRelatedFiles($attributes);
        $this->createProjectStatusHistoryRecord($id, $attributes['status_id']);
        $this->crowdSourcingProjectRepository->update($attributes, $id);
        $this->updateCommunicationResources($project, $attributes);
        if ($attributes['status_id'] === CrowdSourcingProjectStatusLkp::DELETED)
            $this->crowdSourcingProjectRepository->delete($id);
        $colors = [];
        for ($i = 0; $i < count($attributes['color_codes']); $i++) {
            array_push($colors, [
                'id' => $attributes['color_ids'][$i],
                'color_name' => $attributes['color_names'][$i],
                'color_code' => $attributes['color_codes'][$i]
            ]);
        }
        $this->crowdSourcingProjectColorsManager->saveColorsForCrowdSourcingProject($colors, $id);
    }

    protected function setDefaultValuesForCommonProjectFields(array $attributes, CrowdSourcingProject $project = null) {
        if (!isset($attributes['slug']) || !$attributes['slug'])
            $attributes['slug'] = Str::slug($attributes['name'], '-');

        if (!isset($attributes['motto']) || !$attributes['motto'])
            $attributes['motto'] = $attributes['name'];

        if (!isset($attributes['about']) || !$attributes['about'])
            $attributes['about'] = $attributes['description'];

        if (!isset($attributes['footer']) || !$attributes['footer'])
            $attributes['footer'] = $attributes['description'];

        if ((!isset($attributes['img_path']) || !$attributes['img_path']) && (!$project || !$project->img_path))
            $attributes['img_path'] = '/images/image_temp.png';

        if ((!isset($attributes['logo_path']) || !$attributes['logo_path']) && (!$project || !$project->logo_path))
            $attributes['logo_path'] = '/images/image_temp.png';

        if ((!isset($attributes['sm_featured_img_path']) || !$attributes['sm_featured_img_path'])
            && (!$project || !$project->sm_featured_img_path))
            $attributes['sm_featured_img_path'] = '/images/image_temp.png';

        if ((!isset($attributes['lp_questionnaire_img_path']) || !$attributes['lp_questionnaire_img_path'])
            && (!$project || !$project->lp_questionnaire_img_path))
            $attributes['lp_questionnaire_img_path'] = '/images/image_temp.png';

        if (!isset($attributes['lp_show_speak_up_btn']))
            $attributes['lp_show_speak_up_btn'] = false;

        return $attributes;
    }

    protected function setDefaultValuesForSocialMediaFields(CrowdSourcingProject $project, array $attributes) {
        if (!isset($attributes['sm_title']) || !$attributes['sm_title'])
            $attributes['sm_title'] = $project->name;
        if (!isset($attributes['sm_description']) || !$attributes['sm_description'])
            $attributes['sm_description'] = strip_tags($project->motto);
        if (!isset($attributes['sm_keywords']) || !$attributes['sm_keywords'])
            $attributes['sm_keywords'] = str_replace(' ', ',', $project->name);

        return $attributes;
    }

    protected function updateCommunicationResources(CrowdSourcingProject $project, array $attributes) {
        $attributesToUpdate = [];
        if (isset($attributes['questionnaire_response_email_intro_text']) && $attributes['questionnaire_response_email_outro_text']) {
            $attributesToUpdate['questionnaire_response_email_intro_text'] = $attributes['questionnaire_response_email_intro_text'];
            $attributesToUpdate['questionnaire_response_email_outro_text'] = $attributes['questionnaire_response_email_outro_text'];
        }
        $attributesToUpdate['should_send_email_after_questionnaire_response'] =
            (isset($attributes['should_send_email_after_questionnaire_response'])
                && $attributes['should_send_email_after_questionnaire_response'] == 'on') ? 1 : 0;

        $this->crowdSourcingProjectCommunicationResourcesManager->createOrUpdateCommunicationResourcesForProject($project, $attributesToUpdate);
    }

    public function populateInitialValuesForProjectIfNotSet(CrowdSourcingProject $project) {
        $project = $this->populateInitialFileValuesForProjectIfNotSet($project);
        return $this->populateInitialColorValuesForProjectIfNotSet($project);
    }

    public function populateInitialColorValuesForProjectIfNotSet(CrowdSourcingProject $project) {
        if (!$project->lp_motto_color)
            $project->lp_motto_color = '#ffffff';
        if (!$project->lp_about_bg_color)
            $project->lp_about_bg_color = '#ffffff';
        if (!$project->lp_about_color)
            $project->lp_about_color = '#666666';
        if (!$project->lp_questionnaire_color)
            $project->lp_questionnaire_color = '#076ec1';
        if (!$project->lp_footer_bg_color)
            $project->lp_footer_bg_color = '#ffffff';
        if (!$project->lp_footer_color)
            $project->lp_footer_color = '#000000';
        if (!$project->lp_questionnaire_btn_color)
            $project->lp_questionnaire_btn_color = '#ffffff';
        if (!$project->lp_questionnaire_btn_bg_color)
            $project->lp_questionnaire_btn_bg_color = '#004f9f';
        if (!$project->lp_questionnaire_goal_title_color)
            $project->lp_questionnaire_goal_title_color = '#076ec1';
        if (!$project->lp_questionnaire_goal_color)
            $project->lp_questionnaire_goal_color = '#333333';
        if (!$project->lp_questionnaire_goal_bg_color)
            $project->lp_questionnaire_goal_bg_color = '#ffffff';
        if (!$project->lp_newsletter_title_color)
            $project->lp_newsletter_title_color = '#076ec1';
        if (!$project->lp_newsletter_color)
            $project->lp_newsletter_color = '#333333';
        if (!$project->lp_newsletter_bg_color)
            $project->lp_newsletter_bg_color = '#f3fafe';
        if (!$project->lp_newsletter_btn_color)
            $project->lp_newsletter_btn_color = '#ffffff';
        if (!$project->lp_newsletter_btn_bg_color)
            $project->lp_newsletter_btn_bg_color = '#004f9f';

        return $project;
    }

    public function populateInitialFileValuesForProjectIfNotSet(CrowdSourcingProject $project) {
        if (!$project->img_path)
            $project->img_path = '/images/image_temp.png';
        if (!$project->logo_path)
            $project->logo_path = '/images/image_temp.png';
        if (!$project->sm_featured_img_path)
            $project->sm_featured_img_path = '/images/image_temp.png';
        if (!$project->lp_questionnaire_img_path)
            $project->lp_questionnaire_img_path = '/images/bgsectionnaire.png';

        return $project;
    }

    protected function storeProjectRelatedFiles(array $attributes) {

        if (isset($attributes['logo'])) {
            $attributes['logo_path'] = FileUploader::uploadAndGetPath($attributes['logo'], 'project_logos');
        }
        if (isset($attributes['img'])) {
            $attributes['img_path'] = FileUploader::uploadAndGetPath($attributes['img'], 'project_img');
        }

        if (isset($attributes['sm_featured_img'])) {
            $attributes['sm_featured_img_path'] = FileUploader::uploadAndGetPath($attributes['sm_featured_img'], 'project_sm_featured_img');
        }

        if (isset($attributes['lp_questionnaire_img'])) {
            $attributes['lp_questionnaire_img_path'] = FileUploader::uploadAndGetPath($attributes['lp_questionnaire_img'], 'project_questionnaire_bg_img');
        }

        return $attributes;
    }

    protected function createProjectStatusHistoryRecord($projectId, $statusId) {
        $this->crowdSourcingProjectStatusHistoryRepository->create([
            'project_id' => $projectId,
            'status_id' => $statusId
        ]);
    }

    public function getCreateEditProjectViewModel(int $id = null): CreateEditCrowdSourcingProject {
        if ($id)
            $project = $this->getCrowdSourcingProject($id);
        else {
            $project = $this->crowdSourcingProjectRepository->getModelInstance();
        }

        if (!$project->communicationResources()->exists())
            $project->communicationResources = $this->crowdSourcingProjectCommunicationResourcesManager->getDefaultModelInstance();

        $project = $this->populateInitialValuesForProjectIfNotSet($project);
        $project->colors = $this->crowdSourcingProjectColorsManager->getColorsForCrowdSourcingProjectOrDefault($project->id);
        $statusesLkp = $this->crowdSourcingProjectStatusManager->getAllCrowdSourcingProjectStatusesLkp();

        $contributorBadge = new ContributorBadge(1, true);
        $contributorBadgeVM = new GamificationBadgeVM($contributorBadge);
        $questionnaire = $this->questionnaireRepository->getModelInstance();
        $questionnaire->title = 'Test Questionnaire';

        $notification = (new QuestionnaireResponded(
            $questionnaire,
            $contributorBadge,
            $contributorBadgeVM,
            $project->communicationResources
        ))->toMail(null)->render();
        return new CreateEditCrowdSourcingProject($project, $statusesLkp, $notification);
    }

    public function getCrowdSourcingProjectsListPageViewModel() {
        $user = Auth::user();
        return new AllCrowdSourcingProjects($this->crowdSourcingProjectAccessManager->getProjectsUserHasAccessToEdit($user));
    }

    public function getUnavailableCrowdSourcingProjectViewModelForLandingPage($project_slug) {
        $project = $this->getCrowdSourcingProjectBySlug($project_slug);
        $projects = $this->getCrowdSourcingProjectsForHomePage();
        switch ($project->status_id) {
            case CrowdSourcingProjectStatusLkp::FINALIZED:
                $message = 'This project is finalized.<br>Thank you for your contribution!';
                break;
            case CrowdSourcingProjectStatusLkp::UNPUBLISHED:
                $message = 'This project is unpublished.';
                break;
            case CrowdSourcingProjectStatusLkp::DELETED:
                $message = 'This project has been archived.';
                break;
            default:
                $message = 'The project is not currently available';
                break;
        }

        return new CrowdSourcingProjectUnavailable($project, $projects, $message);
    }

    public function getAllCrowdSourcingProjects(): Collection {
        return $this->crowdSourcingProjectRepository->all();
    }

    public function cloneProject(int $id): CrowdSourcingProject {
        return DB::transaction(function () use ($id) {
            $now = Date::now();
            $project = $this->getCrowdSourcingProject($id);
            $project->load(['language', 'status', 'communicationResources']);
            $clone = $project->replicate();
            $clone->name .= ' - Clone';
            $clone->created_at = $now;
            $clone->updated_at = $now;
            $clone->user_creator_id = Auth::id();

            foreach ($project->colors as $color) {
                $clone->colors()->attach($color, ['created_at' => $now, 'updated_at' => $now]);
                // you may set the timestamps to the second argument of attach()
            }
            if($clone->img_path)
                $clone->img_path = $this->copyProjectFile($clone->img_path);
            if($clone->logo_path)
                $clone->logo_path = $this->copyProjectFile($clone->logo_path);
            if($clone->lp_questionnaire_img_path)
                $clone->lp_questionnaire_img_path = $this->copyProjectFile($clone->lp_questionnaire_img_path);
            if($clone->sm_featured_img_path)
                $clone->sm_featured_img_path = $this->copyProjectFile($clone->sm_featured_img_path);
            $clone->push();
            return $clone;
        });
    }

    protected function copyProjectFile(string $filePath): string {
        if (!$filePath)
            return "";
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $newFile = basename($filePath, "." . $ext);
        $newFile .= "_" . now()->getTimestamp() . "." . $ext;
        $file = basename($filePath, "." . $ext);
        $lastDirName = basename(dirname($filePath));
        Storage::copy('public/uploads/' . $lastDirName . '/' . $file . "." . $ext, 'public/uploads/' . $lastDirName . '/' . $newFile);
        return '/storage/uploads/' . $lastDirName . '/' . $newFile;
    }
}