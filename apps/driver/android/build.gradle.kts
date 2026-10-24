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
// google_navigation_flutter pins compileSdk 35, but other plugins (flutter_plugin_android_lifecycle) need 36+.
// Compile every Android library at 36 or newer. Reflection keeps this independent of the AGP version's DSL types.
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
