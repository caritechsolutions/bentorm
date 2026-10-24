allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
// Older plugins (flutter_webrtc 0.14 compiles at 31) must compile at 36+ for current AndroidX libraries.
// Same block as the driver app; reflection keeps it independent of the AGP version's DSL types.
subprojects {
    afterEvaluate {
        val android = extensions.findByName("android") ?: return@afterEvaluate
        try {
            val current = android.javaClass.getMethod("getCompileSdk").invoke(android) as Int?
            if (current == null || current < 36) {
                android.javaClass.getMethod("setCompileSdk", Integer::class.javaObjectType).invoke(android, 36)
            }
        } catch (e: Exception) {
            logger.warn("Could not raise compileSdk for ${project.name}: ${e.message}")
        }
    }
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
