<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                {{-- Is the project has just been created, the user should complete it first --}}
                @if (!$viewModel->isEditMode())
                    Please <b>save the project first </b> to start translating.

                @else
                {{-- the view model should be extended to bring also
                 the language look up and
                 crowd_sourcing_project_translations data for this project
                  --}}
                <crowd-sourcing-project-translations
                        :available-languages='[
                        {
                            "id":1,
                            "language_name":"Bulgarian",
                            "language_code":"bg"
                        },
                        {
                            "id":6,
                            "language_name":"English",
                            "language_code":"en"
                        },
                        {
                            "id":11,
                            "language_name":"Greek",
                            "language_code":"el"
                        }
                        ]'
                    :existing-translations=' [{
                            "language_id": 6,
                            "name" : " original translation name",
                            "motto_title": " original translation moto",
                            "motto_subtitle": " original translation subtitle",
                            "description": " original translation descritpion",
                            "about" :" original translation about",
                            "footer": " original translation footer",
                            "sm_title": " original translation sm title",
                            "sm_description": " original translation sm descr",
                            "sm_keywords": " original translation sm keywords"
                          },
                            {
                              "language_id": 1,
                              "name" : "other name",
                              "motto_title" : "other moto",
                              "motto_subtitle":"other subtitle ",
                              "description": "other descritpion",
                              "about" : "other about",
                              "footer": "other footer",
                              "sm_title" : "other sm title",
                              "sm_description": "other sm descr",
                              "sm_keywords": "other  sm keywords"
                            },
                             {
                              "language_id": 2,
                              "name" : "other name 2",
                              "motto_title" : "other moto2",
                              "motto_subtitle":"other subtitle 2",
                              "description": "other descritpion 2",
                              "about" : "other about 2",
                              "footer": "other footer 2",
                              "sm_title" : "other sm title 2",
                              "sm_description": "other sm descr 2",
                              "sm_keywords": "other  sm keywords 2"
                            }
                          ]'
                />




                @endif

            </div>
        </div>
    </div>
</div>

